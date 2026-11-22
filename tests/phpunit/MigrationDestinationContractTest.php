<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\comment\CommentStorage;
use Drupal\comment\Entity\Comment;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\TableMappingInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\labdoo_migrate\Model\FieldModel;
use Drupal\labdoo_migrate\Model\MappingModel;
use Drupal\labdoo_migrate\Services\DestinationContent\CommentDestinationRepository;
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepository;
use Drupal\labdoo_migrate\Services\DestinationContent\TranslationRepository;
use Drupal\labdoo_migrate\Services\DestinationContent\TranslationRepositoryInterface;
use Drupal\labdoo_migrate\Services\DestinationContent\UserDestinationRepository;
use Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorage;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers destination persistence, transient metadata and mapped field values.
 */
#[Group('labdoo_migrate')]
class MigrationDestinationContractTest extends UnitTestCase {

  /**
   * Builds real repositories with controlled storage and no database writes.
   */
  private function repository(string $kind, bool $cacheFailure = FALSE, bool $unsupportedMapping = FALSE): DestinationRepository|UserDestinationRepository {
    $storage = $this->createMock($kind === 'comment' ? CommentStorage::class : NodeStorage::class);
    $definition = $this->createMock(EntityTypeInterface::class);
    $definition->method('isPersistentlyCacheable')->willReturn(FALSE);
    $storage->method('getEntityType')->willReturn($definition);
    $storage->method('getTableMapping')->willReturn($this->createMock($unsupportedMapping ? TableMappingInterface::class : DefaultTableMapping::class));
    if ($cacheFailure) {
      $changes = [];
      $definition->method('set')->willReturnCallback(static function ($name, $value) use (&$changes, $definition) {
        $changes[] = [$name, $value];
        return $definition;
      });
      $storage->method('resetCache')->willThrowException(new \RuntimeException('Injected cache reset failure'));
      $definition->expects($this->exactly(2))->method('set')->with('persistent_cache', $this->callback(static function ($value) use (&$changes) {
        return $value === (count($changes) === 0);
      }));
    }
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturn($storage);
    $logger = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));
    $tracker = $this->createMock(MigrationTrackerInterface::class);
    $tracker->expects($this->never())->method('track');
    $state = $this->createMock(StateInterface::class);
    if ($kind === 'user') {
      return new UserDestinationRepository($manager, $logger, $tracker, $state);
    }
    $fields = $this->createMock(EntityFieldManagerInterface::class);
    if ($unsupportedMapping) {
      $fields->expects($this->never())->method('getFieldStorageDefinitions');
    }
    else {
      $fields->method('getFieldStorageDefinitions')->willReturn([]);
    }
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('delete');
    $class = $kind === 'comment' ? CommentDestinationRepository::class : DestinationRepository::class;
    return new $class($manager, $logger, $this->createMock(TranslationRepositoryInterface::class), $tracker, $fields, $database, $state);
  }

  /**
   * Provides all destination types and Core save result values.
   */
  public static function saveResults(): array {
    $cases = [];
    foreach (['node', 'comment', 'user'] as $kind) {
      foreach ([0, 1, 2] as $status) {
        $cases[$kind . '-' . $status] = [$kind, $status];
      }
    }
    return $cases;
  }

  /**
   * Both create and update statuses must remain successful booleans.
   */
  #[DataProvider('saveResults')]
  public function testSaveResults(string $kind, int $status): void {
    $class = ['node' => Node::class, 'comment' => Comment::class, 'user' => User::class][$kind];
    $entity = $this->createMock($class);
    $entity->method('getEntityTypeId')->willReturn($kind);
    $entity->method('getEntityType')->willReturn($this->createMock(EntityTypeInterface::class));
    $entity->method('id')->willReturn(42);
    $entity->expects($this->once())->method('save')->willReturn($status);
    $repository = $this->repository($kind);
    $method = new \ReflectionMethod($repository, 'saveEntity');
    $this->assertSame($status !== 0, $method->invoke($repository, $entity));
  }

  /**
   * Provides retryable destination failures and exhausted retry outcomes.
   */
  public static function saveFailures(): array {
    return [
      'node recovers from storage failure' => ['node', TRUE, FALSE],
      'comment recovers from error' => ['comment', TRUE, TRUE],
      'node retry fails' => ['node', FALSE, TRUE],
      'comment retry fails' => ['comment', FALSE, FALSE],
      'user fails without retry' => ['user', FALSE, FALSE],
    ];
  }

  /**
   * Save recovery must preserve retry counts without touching any field rows.
   */
  #[DataProvider('saveFailures')]
  public function testSaveFailure(string $kind, bool $recovers, bool $error): void {
    $class = ['node' => Node::class, 'comment' => Comment::class, 'user' => User::class][$kind];
    $entity = $this->createMock($class);
    $entity->method('getEntityTypeId')->willReturn($kind);
    $entity->method('getEntityType')->willReturn($this->createMock(EntityTypeInterface::class));
    $entity->method('id')->willReturn(42);
    $calls = 0;
    $entity->expects($this->exactly($kind === 'user' ? 1 : 2))->method('save')->willReturnCallback(static function () use (&$calls, $recovers, $error): int {
      if (++$calls === 1 || !$recovers) {
        throw $error ? new \Error('Injected save error') : new EntityStorageException('Injected save failure');
      }
      return 2;
    });
    $repository = $this->repository($kind);
    $this->assertSame($recovers, (new \ReflectionMethod($repository, 'saveEntity'))->invoke($repository, $entity));
  }

  /**
   * Cache restoration must run even when cache invalidation itself throws.
   */
  public function testCacheConfigurationRestoredAfterFailure(): void {
    $node = $this->createMock(Node::class);
    $node->method('getEntityTypeId')->willReturn('node');
    $node->method('id')->willReturn(42);
    $node->expects($this->exactly(2))->method('save')->willReturn(2);
    $repository = $this->repository('node', TRUE);
    $this->assertTrue((new \ReflectionMethod($repository, 'saveEntity'))->invoke($repository, $node));
  }

  /**
   * Metadata must preserve scalars and NULL through Core's non-field API.
   */
  public function testTransientMetadata(): void {
    $node = $this->createPartialMock(Node::class, ['getFieldDefinitions']);
    $node->method('getFieldDefinitions')->willReturn([]);
    $repository = $this->repository('node');
    $method = new \ReflectionMethod($repository, 'setMigrationMetadata');
    foreach (['original_entity_id' => NULL, 'labdoo_skip_geocoding' => TRUE, 'search_api_skip_tracking' => TRUE] as $name => $value) {
      $method->invoke($repository, $node, $name, $value);
      $this->assertSame($value, $node->__get($name));
    }
    $method->invoke($repository, $node, 'original_entity_id', 42);
    $this->assertSame(42, $node->__get('original_entity_id'));
  }

  /**
   * Unsupported content implementations must fail before writing metadata.
   */
  public function testUnsupportedMetadataImplementation(): void {
    $repository = $this->repository('node');
    $this->expectException(\InvalidArgumentException::class);
    (new \ReflectionMethod($repository, 'setMigrationMetadata'))->invoke($repository, $this->createMock(ContentEntityInterface::class), 'original_entity_id', 42);
  }

  /**
   * Purge recovery must not call implementation-specific methods on a mapper.
   */
  public function testUnsupportedTableMappingsAreNotPurged(): void {
    foreach (['node' => Node::class, 'comment' => Comment::class] as $kind => $class) {
      $entity = $this->createMock($class);
      $entity->method('id')->willReturn(42);
      $entity->method('getEntityTypeId')->willReturn($kind);
      $entity->method('getEntityType')->willReturn($this->createMock(EntityTypeInterface::class));
      $calls = 0;
      $entity->expects($this->exactly(2))->method('save')->willReturnCallback(static function () use (&$calls): int {
        if (++$calls === 1) {
          throw new EntityStorageException('Injected save failure');
        }
        return 2;
      });
      $repository = $this->repository($kind, FALSE, TRUE);
      $this->assertTrue((new \ReflectionMethod($repository, 'saveEntity'))->invoke($repository, $entity));
    }
  }

  /**
   * User references must use the field list API and preserve duplicate checks.
   */
  public function testUserFieldAppend(): void {
    $field = $this->createMock(FieldItemList::class);
    $field->method('getValue')->willReturn([['value' => 'existing']]);
    $field->expects($this->once())->method('appendItem')->with('new');
    $storage = $this->createMock(FieldStorageDefinitionInterface::class);
    $storage->method('isMultiple')->willReturn(TRUE);
    $definition = $this->createMock(FieldDefinitionInterface::class);
    $definition->method('getFieldStorageDefinition')->willReturn($storage);
    $user = $this->createMock(UserInterface::class);
    $user->method('get')->with('field_links')->willReturn($field);
    $user->method('getFieldDefinition')->willReturn($definition);
    $repository = $this->repository('user');
    $method = new \ReflectionMethod($repository, 'setOrAppend');
    $method->invoke($repository, $user, 'field_links', 'existing');
    $method->invoke($repository, $user, 'field_links', 'new');
  }

  /**
   * Mapping must retain scalar and repeated values and honor dry-run mode.
   */
  public function testMappedFieldsInDryRun(): void {
    $repository = $this->repository('node');
    $mapping = [];
    foreach (['title' => 'title', 'tag1' => 'field_tags', 'tag2' => 'field_tags'] as $source => $target) {
      $mapping[$source] = new MappingModel([], new FieldModel('', '', $target));
    }
    (new \ReflectionProperty($repository, 'mapping'))->setValue($repository, $mapping);
    (new \ReflectionProperty($repository, 'dryRun'))->setValue($repository, TRUE);
    $node = $this->createMock(Node::class);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('getFieldDefinition')->willReturnCallback(function (string $name): FieldDefinitionInterface {
      $storage = $this->createMock(FieldStorageDefinitionInterface::class);
      $storage->method('isMultiple')->willReturn($name === 'field_tags');
      $definition = $this->createMock(FieldDefinitionInterface::class);
      $definition->method('getFieldStorageDefinition')->willReturn($storage);
      return $definition;
    });
    $writes = [];
    $node->method('set')->willReturnCallback(static function ($name, $value) use (&$writes, $node) {
      $writes[] = [$name, $value];
      return $node;
    });
    $node->expects($this->never())->method('save');
    $result = (new \ReflectionMethod($repository, 'updateEntity'))->invoke($repository, [
      'title' => ['value' => 'Mapped title'],
      'tag1' => 'A',
      'tag2' => 'B',
    ], $node, 'en');
    $this->assertTrue($result);
    $this->assertSame([['title', NULL], ['title', 'Mapped title'], ['field_tags', []], ['field_tags', ['A', 'B']]], $writes);
  }

  /**
   * Existing and new translations must keep the source entity's concrete type.
   */
  public function testTranslationVariants(): void {
    $repository = (new \ReflectionClass(TranslationRepository::class))->newInstanceWithoutConstructor();
    foreach ([TRUE, FALSE] as $exists) {
      $node = $this->createMock(NodeInterface::class);
      $translation = $this->createMock(NodeInterface::class);
      $node->method('hasTranslation')->with('es')->willReturn($exists);
      $node->expects($exists ? $this->once() : $this->never())->method('getTranslation')->with('es')->willReturn($translation);
      $node->expects($exists ? $this->never() : $this->once())->method('addTranslation')->with('es')->willReturn($translation);
      $this->assertSame($translation, $repository->getEntityTranslation($node, 'es'));
    }
  }

  /**
   * Configuration entities must not enter the content translation path.
   */
  public function testNonTranslatableEntity(): void {
    $repository = (new \ReflectionClass(TranslationRepository::class))->newInstanceWithoutConstructor();
    $this->expectException(\InvalidArgumentException::class);
    $repository->getEntityTranslation($this->createMock(EntityInterface::class), 'es');
  }

  /**
   * Existing destination IDs require an explicit override during creation.
   */
  #[DataProvider('existingDestinationCases')]
  public function testExistingDestinationOverride(string $kind, bool $override): void {
    $repository = $this->repository($kind);
    $entity = $this->createMock($kind === 'user' ? UserInterface::class : NodeInterface::class);
    $entity->expects($this->never())->method('save');
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(42)->willReturn($entity);
    $storage->expects($this->never())->method('create');
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with($kind)->willReturn($storage);
    (new \ReflectionProperty($repository, 'entityTypeManager'))->setValue($repository, $manager);
    $repository->setOverrideMode($override);
    if (!$override) {
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('explicit override is required');
    }
    $arguments = $kind === 'user' ? [42] : [42, 'team_post', 'en'];
    $result = (new \ReflectionMethod($repository, 'prepareEntity'))->invokeArgs($repository, $arguments);
    $this->assertSame($entity, $result);
  }

  /**
   * Covers safe defaults and explicit replacement for nodes and users.
   */
  public static function existingDestinationCases(): array {
    return [['node', FALSE], ['node', TRUE], ['user', FALSE], ['user', TRUE]];
  }

}
