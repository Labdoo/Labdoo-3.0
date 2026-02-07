<?php

namespace Drupal\labdoo_hub\Service\Export;

use Drupal\labdoo_common\Service\Export\BaseCsvExport;

/**
 * Exports hub view data into CSV.
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
      'Hub',
      'Needed (N)',
      'In transit (T)',
      'Delivered (D)',
      'Remaining (R = N-T-D)',
      'Completed',
      'Country',
      'Semaphore',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getRowData(object $entity): array {
    return [
      $entity->label(),
      $entity->hasField('field_dootronics_needed') ? $entity->get('field_dootronics_needed')->value : '',
      $entity->hasField('field_dootronics_in_transit') ? $entity->get('field_dootronics_in_transit')->value : '',
      $entity->hasField('field_dootronics_delivered') ? $entity->get('field_dootronics_delivered')->value : '',
      $entity->hasField('field_dootronics_remaining') ? $entity->get('field_dootronics_remaining')->value : '',
      $entity->hasField('field_dootronics_completed') ? $entity->get('field_dootronics_completed')->value : '',
      $entity->hasField('field_country') ? $entity->get('field_country')->value : '',
      '',
    ];
  }

}
