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
      'Title',
      'Status',
      'Hub',
      'Edoovillage',
      'Country',
      'Serial number',
      'Pick me up',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getRowData(object $entity): array {
    return [
      $entity->label(),
      $entity->hasField('field_dootronic_status') ? $entity->get('field_dootronic_status')->view(['label' => 'hidden'])[0]['#markup'] : '',
      $entity->hasField('field_hub') && $entity->get('field_hub')->entity ? $entity->get('field_hub')->entity->label() : '',
      $entity->hasField('field_edoovillage_destination') && $entity->get('field_edoovillage_destination')->entity ? $entity->get('field_edoovillage_destination')->entity->label() : '',
      $entity->hasField('field_country') ? $entity->get('field_country')->view(['label' => 'hidden'])[0]['#plain_text'] : '',
      $entity->hasField('field_serial_number') ? $entity->get('field_serial_number')->value : '',
      $entity->hasField('field_pick_me_up') ? ($entity->get('field_pick_me_up')->value ? 'yes' : 'no') : 'no',
    ];
  }

}
