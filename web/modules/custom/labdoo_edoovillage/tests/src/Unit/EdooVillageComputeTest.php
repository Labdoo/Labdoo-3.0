<?php

namespace Drupal\Tests\labdoo_edoovillage\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\labdoo_edoovillage\Service\Compute\EdooVillageCompute;
use Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface;
use Drupal\labdoo_edoovillage\Service\Queue\Feeder\QueueFeederInterface;

/**
 * @coversDefaultClass \Drupal\labdoo_edoovillage\Service\Compute\EdooVillageCompute
 * @group labdoo_edoovillage
 */
class EdooVillageComputeTest extends UnitTestCase {

  /**
   * Tests a cloned village receives a generated title and available ID.
   *
   * @covers ::setEdooVillageTitle
   */
  public function testCloneGetsGeneratedTitle(): void {
    $repository = $this->createMock(EdooVillageRepositoryInterface::class);
    $repository->expects($this->once())
      ->method('generateId')
      ->willReturn(3176);
    $repository->expects($this->once())->method('commit');

    $countryManager = $this->createMock(\Drupal\Core\Locale\CountryManagerInterface::class);
    $countryManager->method('getList')->willReturn(['DE' => 'Germany']);

    $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
    $container->set('labdoo_edoovillage.repository', $repository);
    $container->set('country_manager', $countryManager);
    \Drupal::setContainer($container);

    $entity = $this->createMock(NodeInterface::class);
    $entity->method('bundle')->willReturn('edoovillage');
    $entity->method('getTitle')->willReturn('Clone of St. Pauls Primary and Secondary School');
    $entity->method('isNew')->willReturn(TRUE);
    $entity->method('hasField')->willReturnCallback(static fn (string $name): bool => in_array($name, [
      'field_city',
      'field_project_summary',
    ], TRUE));
    $entity->method('get')->willReturnCallback(function (string $name): FieldItemListInterface {
      $field = $this->createMock(FieldItemListInterface::class);
      $value = match ($name) {
        'field_country' => 'DE',
        'field_city' => 'Bonn',
        'field_project_summary' => 'St. Pauls Primary and Secondary School',
        default => NULL,
      };
      $field->method('isEmpty')->willReturn($value === NULL || $value === '');
      $field->method('__get')->willReturnCallback(static fn (string $property) => $property === 'value' ? $value : NULL);
      return $field;
    });
    $entity->expects($this->once())
      ->method('setTitle')
      ->with('Edoovillage #3176 - Germany, Bonn: St. Pauls Primary and Secondary School');

    $compute = new EdooVillageCompute($this->createMock(QueueFeederInterface::class));
    $compute->setEdooVillageTitle($entity);
  }

}
