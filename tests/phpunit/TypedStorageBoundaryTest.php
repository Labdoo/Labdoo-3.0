<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\geocoder\GeocoderProviderInterface;
use Drupal\labdoo_common\Utility\GeocoderProviders;
use Drupal\labdoo_migrate\Services\Incremental\ReadOnlyEntityLoader;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Checks missing, incompatible and bulk entity loads without a database.
 */
class TypedStorageBoundaryTest extends UnitTestCase {

  /**
   * Existing scoped storage instances restored after each test.
   */
  private array $previousStorages;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->previousStorages = (new \ReflectionProperty(ReadOnlyEntityLoader::class, 'storages'))->getValue();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    (new \ReflectionProperty(ReadOnlyEntityLoader::class, 'storages'))->setValue(NULL, $this->previousStorages);
    parent::tearDown();
  }

  /**
   * Supplies each supported content storage and its actual entity contract.
   */
  public static function storages(): array {
    return [
      ['node', NodeInterface::class],
      ['user', UserInterface::class],
      ['file', FileInterface::class],
      ['media', MediaInterface::class],
    ];
  }

  /**
   * Missing references stay nullable; valid results preserve object identity.
   */
  #[DataProvider('storages')]
  public function testSingleLoad(string $type, string $interface): void {
    $entity = $this->createMock($interface);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->exactly(2))->method('load')->with(42)->willReturnOnConsecutiveCalls(NULL, $entity);
    (new \ReflectionProperty(ReadOnlyEntityLoader::class, 'storages'))->setValue(NULL, [$type => $storage]);
    $this->assertNull(ReadOnlyEntityLoader::$type(42));
    $this->assertSame($entity, ReadOnlyEntityLoader::$type(42));
  }

  /**
   * A storage implementation returning an unrelated entity must fail closed.
   */
  #[DataProvider('storages')]
  public function testIncompatibleSingleLoad(string $type, string $interface): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($this->createMock(EntityInterface::class));
    (new \ReflectionProperty(ReadOnlyEntityLoader::class, 'storages'))->setValue(NULL, [$type => $storage]);
    $this->expectException(\UnexpectedValueException::class);
    ReadOnlyEntityLoader::$type(42);
  }

  /**
   * Bulk reads retain IDs, omit absent entities and use one storage call.
   */
  public function testBulkNodes(): void {
    $node = $this->createMock(NodeInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('loadMultiple')->with([42, 99])->willReturn([42 => $node]);
    $storage->expects($this->never())->method('load');
    (new \ReflectionProperty(ReadOnlyEntityLoader::class, 'storages'))->setValue(NULL, ['node' => $storage]);
    $this->assertSame([42 => $node], ReadOnlyEntityLoader::nodes([42, 99]));
  }

  /**
   * Provider configuration IDs must resolve to provider objects, never strings.
   */
  public function testProviders(): void {
    $provider = $this->createMock(GeocoderProviderInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->exactly(2))->method('loadMultiple')->with(['googlemaps'])->willReturnOnConsecutiveCalls(['googlemaps' => $provider], []);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('geocoder_provider')->willReturn($storage);
    $this->assertSame(['googlemaps' => $provider], GeocoderProviders::load($manager, ['googlemaps']));
    $this->assertSame([], GeocoderProviders::load($manager, ['googlemaps']));
  }

  /**
   * Invalid provider configuration must fail before invoking an external API.
   */
  public function testInvalidProvider(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn([$this->createMock(EntityInterface::class)]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->willReturn($storage);
    $this->expectException(\UnexpectedValueException::class);
    GeocoderProviders::load($manager, ['googlemaps']);
  }

}
