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
      'title',
      'created',
      'country',
      'status',
      'needed',
      'delivered',
      'in transit',
      'remaining'
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
      $entity->get('field_country')->value,
      $entity->get('field_hub_status')->value,
      $entity->get('field_dootronics_needed')->value,
      $entity->get('field_dootronics_delivered')->value,
      $entity->get('field_dootronics_in_transit')->value,
      $entity->get('field_dootronics_remaining')->value,
    ];
  }

}
