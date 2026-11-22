<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\labdoo_dootrip\Service\Compute\DootripCompute;
use Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface;
use Drupal\labdoo_dootronics\Service\Compute\DootronicCompute;
use Drupal\node\NodeStorageInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Ensures generic entity hooks do not send non-nodes into node computations.
 */
#[Group('labdoo_compute')]
class NodeComputeBoundaryTest extends UnitTestCase {

  /**
   * Provides generic hook entry points for the three node computation domains.
   */
  public static function hooks(): array {
    $cases = [];
    foreach (['dootronics', 'dootrip', 'edoovillage'] as $domain) {
      foreach (['presave', 'insert', 'update', 'delete'] as $operation) {
        $module = 'labdoo_' . $domain;
        $cases[$module . ':' . $operation] = [$module, $module . '_entity_' . $operation];
      }
    }
    return $cases;
  }

  /**
   * Non-node entities must never reach bundle-specific services or fields.
   */
  #[DataProvider('hooks')]
  public function testNonNodesAreIgnored(string $module, string $hook): void {
    require_once dirname(__DIR__, 2) . '/web/modules/custom/' . $module . '/' . $module . '.module';
    $entity = $this->createMock(EntityInterface::class);
    $entity->expects($this->never())->method('bundle');
    $entity->expects($this->never())->method('id');
    $hook($entity);
  }

  /**
   * Provides empty, complete and incomplete weight references.
   */
  public static function weights(): array {
    return [
      'empty' => [[], 'Please update the weight of each individual dootronic'],
      'complete' => [[2.5, 1.5], '4 Kgms'],
      'incomplete' => [
        [2.5, NULL],
        'More than 2.5 Kgms (Please update the weight field in all the doojects for an accurate weight estimation)',
      ],
    ];
  }

  /**
   * Reading referenced entities must preserve weight totals and missing data.
   */
  #[DataProvider('weights')]
  public function testWeightReferences(array $weights, string $expected): void {
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
    $references = [];
    foreach ($weights as $weight) {
      $field = $this->createMock(FieldItemList::class);
      $field->method('__get')->with('value')->willReturn($weight);
      $node = $this->createMock(NodeInterface::class);
      $node->method('get')->with('field_weight')->willReturn($field);
      $references[] = $node;
    }
    $laptops = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $laptops->method('referencedEntities')->willReturn($references);
    $trip = $this->createMock(NodeInterface::class);
    $trip->method('get')->with('field_laptops')->willReturn($laptops);
    $trip->expects($this->once())->method('set')->with('field_dootrip_weight', $this->callback(static fn ($value) => (string) $value === $expected))->willReturnSelf();
    $compute = (new \ReflectionClass(DootripCompute::class))->newInstanceWithoutConstructor();
    $compute->computeWeight($trip);
  }

  /**
   * Provides repeated, missing and already consistent reciprocal links.
   */
  public static function reciprocalLinks(): array {
    $cases = [];
    foreach (['device', 'trip'] as $direction) {
      $cases[$direction . ': missing link'] = [$direction, TRUE, FALSE];
      $cases[$direction . ': existing link'] = [$direction, TRUE, TRUE];
      $cases[$direction . ': deleted reference'] = [$direction, FALSE, FALSE];
    }
    return $cases;
  }

  /**
   * Repeated recomputation must not duplicate links or save unchanged nodes.
   */
  #[DataProvider('reciprocalLinks')]
  public function testReciprocalLinks(string $direction, bool $present, bool $linked): void {
    $source = $this->createMock(NodeInterface::class);
    $source->method('id')->willReturn(901);
    $source->method('isNew')->willReturn(FALSE);
    $related = $this->createMock(NodeInterface::class);
    $related->method('id')->willReturn(902);
    $writes = $present && !$linked ? 1 : 0;
    $related->expects($this->exactly($writes))->method('save')->willReturn(1);
    $source->expects($this->never())->method('save');

    $sourceField = $direction === 'device' ? 'field_dootrips' : 'field_laptops';
    $relatedField = $direction === 'device' ? 'field_laptops' : 'field_dootrips';
    $references = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $references->method('referencedEntities')->willReturn($present ? [$related] : []);
    $source->method('get')->with($sourceField)->willReturn($references);

    $items = $linked ? [(object) ['target_id' => 901, 'entity' => $source]] : [];
    $reciprocal = $this->createMock(EntityReferenceFieldItemList::class);
    $reciprocal->method('getIterator')->willReturnCallback(static function () use (&$items) {
      return new \ArrayIterator($items);
    });
    $reciprocal->expects($this->exactly($writes))->method('appendItem')->with(901)->willReturnCallback(function () use (&$items, $source) {
      $items[] = (object) ['target_id' => 901, 'entity' => $source];
      return $this->createMock(FieldItemInterface::class);
    });
    $related->method('get')->with($relatedField)->willReturn($reciprocal);

    $storage = $this->createMock(NodeStorageInterface::class);
    $storage->expects($this->exactly($writes))->method('resetCache')->with([902]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('node')->willReturn($storage);
    $tripCompute = $this->createMock(DootripComputeInterface::class);
    $tripCompute->expects($this->exactly($direction === 'device' ? $writes : 0))
      ->method('enqueueCapacityRecompute')->with($related);
    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $manager);
    $container->set('labdoo_dootrip.compute', $tripCompute);
    \Drupal::setContainer($container);

    if ($direction === 'device') {
      $compute = (new \ReflectionClass(DootronicCompute::class))->newInstanceWithoutConstructor();
      $compute->computeRelatedDootrips($source);
      $compute->computeRelatedDootrips($source);
    }
    else {
      $compute = (new \ReflectionClass(DootripCompute::class))->newInstanceWithoutConstructor();
      $compute->computeRelatedDootronics($source);
      $compute->computeRelatedDootronics($source);
    }
    $this->assertCount($linked || $writes ? 1 : 0, $items);
  }

}
