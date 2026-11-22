<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\geocoder\GeocoderInterface;
use Drupal\geocoder\GeocoderProviderInterface;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_global_action\Service\ActionGenerator\AbstractActionGenerator;
use Drupal\labdoo_global_action\Commands\GlobalActionCommands;
use Drupal\labdoo_global_action\Service\Repository\GlobalActionRepository;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\Tests\UnitTestCase;
use Geocoder\Model\AddressCollection;
use Labdoo\Tests\Unit\Support\ActionGeneratorProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__ . '/Support/ActionGeneratorProbe.php';

/**
 * Tests nullable reference IDs, provider objects and empty geocoder results.
 */
#[CoversClass(AbstractActionGenerator::class)]
#[CoversClass(GlobalActionCommands::class)]
#[Group('labdoo_global_action')]
class ActionGeneratorContractTest extends UnitTestCase {

  /**
   * Provides field-storage ID representations, missing values and invalid IDs.
   */
  public static function references(): array {
    return [
      'numeric string' => [TRUE, '42', 42, FALSE],
      'integer' => [TRUE, 42, 42, FALSE],
      'leading zeroes' => [TRUE, '0042', 42, FALSE],
      'empty reference' => [TRUE, NULL, NULL, FALSE],
      'missing field' => [FALSE, NULL, NULL, FALSE],
      'invalid reference' => [TRUE, 'not-an-id', NULL, TRUE],
      'overflowing reference' => [TRUE, (string) PHP_INT_MAX . '0', NULL, TRUE],
    ];
  }

  /**
   * Builds fully controlled dependencies, without bootstrapping a Drupal site.
   */
  private function probe(?GeocoderProviderInterface $provider = NULL, ?GeocoderInterface $geocoder = NULL, bool $disabled = FALSE): ActionGeneratorProbe {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('googlemaps')->willReturn($provider);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('geocoder_provider')->willReturn($storage);
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('geocoder_presave_disabled')->willReturn($disabled);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('geocoder.settings')->willReturn($config);
    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    \Drupal::setContainer($container);
    return new ActionGeneratorProbe($geocoder ?? $this->createMock(GeocoderInterface::class), $this->createMock(CommonRepository::class), $manager);
  }

  /**
   * Numeric references must remain numeric and missing references remain NULL.
   */
  #[DataProvider('references')]
  public function testReferenceIds(bool $present, int|string|null $raw, ?int $expected, bool $invalid): void {
    $field = $this->createMock(FieldItemList::class);
    $field->method('getValue')->willReturn([['target_id' => $raw]]);
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_hub')->willReturn($present);
    $node->method('get')->with('field_hub')->willReturn($field);
    if ($invalid) {
      $this->expectException(\UnexpectedValueException::class);
    }
    $actual = $this->probe()->reference($node, 'field_hub');
    $this->assertSame($expected, $actual);
  }

  /**
   * Provider objects must be passed to the supported geocoder API.
   */
  public function testEmptyCollectionIsSafe(): void {
    $provider = $this->createMock(GeocoderProviderInterface::class);
    $geocoder = $this->createMock(GeocoderInterface::class);
    $geocoder->expects($this->once())->method('reverse')->with('40.4', '-3.7', [$provider])->willReturn(new AddressCollection([]));
    $this->assertNull($this->probe($provider, $geocoder)->reverseResult('40.4', '-3.7'));
  }

  /**
   * Missing provider configuration must resolve to an empty provider list.
   */
  public function testMissingProvider(): void {
    $this->assertSame([], $this->probe()->providers());
  }

  /**
   * Explicitly disabled geocoding must not call the provider service.
   */
  public function testDisabledGeocoding(): void {
    $geocoder = $this->createMock(GeocoderInterface::class);
    $geocoder->expects($this->never())->method('reverse');
    $this->assertNull($this->probe(NULL, $geocoder, TRUE)->reverseResult('40.4', '-3.7'));
  }

  /**
   * Generic entity hooks must not dispatch non-nodes into node generators.
   */
  public function testNonNodeIsIgnored(): void {
    require_once dirname(__DIR__, 2) . '/web/modules/custom/labdoo_global_action/labdoo_global_action.module';
    $entity = $this->createMock(EntityInterface::class);
    $entity->expects($this->never())->method('bundle');
    labdoo_global_action_create_global_action($entity);
  }

  /**
   * Empty rebuilds must restore configuration without deleting any entities.
   */
  public function testEmptyRebuildRestoresGeocoderConfiguration(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('sort')->willReturnSelf();
    $query->expects($this->exactly(2))->method('execute')->willReturn([]);
    $storage = $this->createMock(NodeStorageInterface::class);
    $storage->expects($this->exactly(2))->method('getQuery')->willReturn($query);
    $storage->expects($this->never())->method('loadMultiple');
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('node')->willReturn($storage);
    $repository = $this->createMock(GlobalActionRepository::class);
    $repository->expects($this->never())->method('createGlobalAction');
    $database = $this->createMock(Connection::class);
    $database->expects($this->never())->method('delete');
    $database->expects($this->never())->method('select');
    $config = $this->createMock(Config::class);
    $config->method('get')->with('geocoder_presave_disabled')->willReturn(FALSE);
    $updates = [];
    $config->method('set')->willReturnCallback(static function ($key, $value) use (&$updates, $config) {
      $updates[] = [$key, $value];
      return $config;
    });
    $config->expects($this->exactly(2))->method('save')->willReturnSelf();
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->expects($this->once())->method('getEditable')->with('geocoder.settings')->willReturn($config);
    $command = new GlobalActionCommands($manager, $repository, $database, $factory);
    $output = new BufferedOutput();
    $command->setOutput($output);
    $command->rebuildActions();
    $this->assertSame([
      ['geocoder_presave_disabled', TRUE],
      ['geocoder_presave_disabled', FALSE],
    ], $updates);
    $text = $output->fetch();
    $this->assertStringContainsString('No source nodes found', $text);
    $this->assertStringContainsString('Geocoder presave setting restored', $text);
  }

}
