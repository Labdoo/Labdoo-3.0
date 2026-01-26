<?php

namespace Drupal\labdoo_dootrip\Service\Export;

use Drupal\labdoo_common\Service\Export\BaseCsvExport;

/**
 * CSV export utility.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class CsvExport extends BaseCsvExport {

  /**
   * {@inheritdoc}
   */
  protected function getHeader(): array {
    return [
      'title',
      'created',
      'country',
      'hub',
      'needed',
      'delivered',
      'in transit',
      'remaining',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getRowData(object $entity): array {
    $created = new \DateTime();
    $created->setTimestamp($entity->getCreatedTime());
    return [
      $entity->label(),
      $created->format('Y-m-d'),
      $entity->hasField('field_country') ? $entity->get('field_country')->value : '',
      $entity->hasField('field_hub') && $entity->get('field_hub')->entity ? $entity->get('field_hub')->entity->label() : '',
      $entity->hasField('field_number_of_laptops_needed') ? $entity->get('field_number_of_laptops_needed')->value : '',
      $entity->hasField('field_dootronics_delivered') ? $entity->get('field_dootronics_delivered')->value : '',
      $entity->hasField('field_dootronics_in_transit') ? $entity->get('field_dootronics_in_transit')->value : '',
      $entity->hasField('field_dootronics_remaining') ? $entity->get('field_dootronics_remaining')->value : '',
    ];
  }

}
