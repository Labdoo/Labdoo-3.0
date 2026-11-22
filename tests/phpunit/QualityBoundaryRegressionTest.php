<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\geocoder\GeocoderInterface;
use Drupal\geocoder\GeocoderProviderInterface;
use Drupal\labdoo_dootrip\Plugin\QueueWorker\DootripGeocoding;
use Drupal\labdoo_dootronics\Plugin\QueueWorker\DootronicGeocoding;
use Drupal\labdoo_edoovillage\Plugin\QueueWorker\EdooVillageGeocoding;
use Drupal\labdoo_hub\Plugin\QueueWorker\HubGeocoding;
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface;
use Drupal\labdoo_migrate\Services\DestinationContent\RepositoryRegistry;
use Drupal\labdoo_migrate\Services\Incremental\ReadOnlyEntityLoader;
use Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface;
use Drupal\labdoo_statistics\Controller\AdvancedStatisticsController;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\Tests\UnitTestCase;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exercises the changed provider, revision, repository and label boundaries.
 */
class QualityBoundaryRegressionTest extends UnitTestCase {

  /**
   * Supplies all workers using reverse geocoding.
   */
  public static function workers(): array {
    return [
      [DootripGeocoding::class],
      [DootronicGeocoding::class],
      [EdooVillageGeocoding::class],
      [HubGeocoding::class],
    ];
  }

  /**
   * Each worker must pass configured provider objects to the real API contract.
   */
  #[DataProvider('workers')]
  public function testWorkerProviders(string $class): void {
    $worker = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    $provider = $this->createMock(GeocoderProviderInterface::class);
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())->method('loadMultiple')->with(['googlemaps'])->willReturn(['googlemaps' => $provider]);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('geocoder_provider')->willReturn($storage);
    $builder = new AddressBuilder('unit');
    $builder->setCoordinates(40.0, -3.0)->setCountry('Spain')->setCountryCode('ES')->setLocality('Madrid');
    $geocoder = $this->createMock(GeocoderInterface::class);
    $geocoder->expects($this->once())->method('reverse')->with('40', '-3', ['googlemaps' => $provider])->willReturn(new AddressCollection([$builder->build()]));
    (new \ReflectionProperty($class, 'entityTypeManager'))->setValue($worker, $manager);
    (new \ReflectionProperty($class, 'geocoder'))->setValue($worker, $geocoder);
    $this->assertSame(['country_code' => 'ES', 'city' => 'Madrid'], (new \ReflectionMethod($class, 'reverseLookupCoordinates'))->invoke($worker, 40.0, -3.0));
  }

  /**
   * A paragraph reference must load its exact revision and matching identity.
   */
  public function testParagraphRevision(): void {
    $property = new \ReflectionProperty(ReadOnlyEntityLoader::class, 'storages');
    $previous = $property->getValue();
    try {
      $paragraph = $this->createMock(ParagraphInterface::class);
      $paragraph->method('id')->willReturn(42);
      $storage = $this->createMock(RevisionableStorageInterface::class);
      $storage->expects($this->exactly(2))->method('loadRevision')->with(123)->willReturn($paragraph);
      $storage->expects($this->never())->method('load');
      $property->setValue(NULL, ['paragraph' => $storage]);
      $item = $this->createMock(FieldItemInterface::class);
      $item->method('getValue')->willReturnOnConsecutiveCalls(
        ['target_id' => 42, 'target_revision_id' => 123],
        ['target_id' => 99, 'target_revision_id' => 123],
      );
      $this->assertSame($paragraph, ReadOnlyEntityLoader::paragraph($item));
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('Dangling or mismatched paragraph revision');
      ReadOnlyEntityLoader::paragraph($item);
    }
    finally {
      $property->setValue(NULL, $previous);
    }
  }

  /**
   * Repository selection preserves identity and rejects unsupported types.
   */
  public function testRepositoryRegistry(): void {
    $source = $this->createMock(SourceRepositoryInterface::class);
    $destination = $this->createMock(DestinationRepositoryInterface::class);
    $registry = new RepositoryRegistry(['node' => $source], ['node' => $destination]);
    $this->assertSame($source, $registry->source('node'));
    $this->assertSame($destination, $registry->destination('node'));
    $this->expectException(\InvalidArgumentException::class);
    $registry->source('unsupported');
  }

  /**
   * Labels use translated configuration; unknown historic values stay readable.
   */
  public function testTranslatedStatisticsLabels(): void {
    $controller = (new \ReflectionClass(AdvancedStatisticsController::class))->newInstanceWithoutConstructor();
    $factory = $this->getConfigFactoryStub([
      'field.storage.node.field_device_type' => [
        'settings' => [
          'allowed_values' => [['value' => 'laptop', 'label' => 'Ordenador portátil']],
        ],
      ],
    ]);
    (new \ReflectionProperty($controller, 'configFactory'))->setValue($controller, $factory);
    $label = new \ReflectionMethod($controller, 'fieldLabel');
    $this->assertSame('Ordenador portátil', $label->invoke($controller, 'field_device_type', 'laptop'));
    $this->assertSame('Legacy device', $label->invoke($controller, 'field_device_type', 'legacy_device'));
  }

}
