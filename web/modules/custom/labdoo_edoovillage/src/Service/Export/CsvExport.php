<?php

namespace Drupal\labdoo_edoovillage\Service\Export;

use Drupal\labdoo_common\Service\Export\BaseCsvExport;

/**
 * Exports edoovillage view data into CSV.
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
      'Date created',
      'Status',
      'Country',
      'Hubs',
      'Needed (N)',
      'Delivered (D)',
      'In transit (T)',
      'Remaining (R)',
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
      $entity->hasField('field_status') ? $entity->get('field_status')->view(['label' => 'hidden'])[0]['#markup'] : '',
      $entity->hasField('field_country') ? $entity->get('field_country')->view(['label' => 'hidden'])[0]['#plain_text'] : '',
      $entity->hasField('field_hub') && $entity->get('field_hub')->entity ? $entity->get('field_hub')->entity->label() : '',
      $entity->hasField('field_number_of_laptops_needed') ? $entity->get('field_number_of_laptops_needed')->value : '',
      $entity->hasField('field_dootronics_delivered') ? $entity->get('field_dootronics_delivered')->value : '',
      $entity->hasField('field_dootronics_in_transit') ? $entity->get('field_dootronics_in_transit')->value : '',
      $entity->hasField('field_dootronics_remaining') ? $entity->get('field_dootronics_remaining')->value : '',
    ];
  }

}
