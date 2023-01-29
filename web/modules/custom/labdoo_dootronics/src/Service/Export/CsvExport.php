<?php

namespace Drupal\labdoo_dootronics\Service\Export;

use Drupal\labdoo_common\Service\Export\BaseCsvExport;

/**
 * Exports dootronics view data into CSV.
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
      'id',
      'status',
      'hub',
      'edoovillage',
      'country',
      'serial number',
      'pick me up',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getRowData(object $entity): array {
    return [
      $entity->id(),
      $entity->get('field_dootronic_status')->value,
      $entity->get('field_hub')->entity ? $entity->get('field_hub')->entity->label() : '',
      $entity->get('field_edoovillage_destination')->entity ? $entity->get('field_edoovillage_destination')->entity->label() : '',
      $entity->get('field_country')->value,
      $entity->get('field_serial_number')->value,
      $entity->get('field_pick_me_up')->value ? 'yes' : 'no',
    ];
  }

}
