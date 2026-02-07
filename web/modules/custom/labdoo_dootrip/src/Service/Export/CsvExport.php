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
      'Dootrip',
      'Departure',
      'Capacity',
      'In transit',
      'Transported',
      'Status',
      'Load',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getRowData(object $entity): array {
    return [
      $entity->label(),
      $entity->hasField('field_departure_date') ? $entity->get('field_departure_date')->value : '',
      $entity->hasField('field_dootrip_capacity') ? $entity->get('field_dootrip_capacity')->value : '',
      $entity->hasField('field_dootronics_in_transit') ? $entity->get('field_dootronics_in_transit')->value : '',
      $entity->hasField('field_dootronics_delivered') ? $entity->get('field_dootronics_delivered')->value : '',
      $entity->hasField('field_status_dootrip') ? $entity->get('field_status_dootrip')->value : '',
      $entity->hasField('field_full') ? ($entity->get('field_full')->value ? 'Yes' : 'No') : 'No',
    ];
  }

}
