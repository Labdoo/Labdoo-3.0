<?php

namespace Drupal\labdoo_dootrip\Service\Export;

use Drupal\labdoo_common\Service\Repository\ViewRepository;

/**
 * CSV export utility.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class CsvExport {

  /**
   * The view repository.
   *
   * @var \Drupal\labdoo_common\Service\Repository\ViewRepository
   */
  protected ViewRepository $viewRepository;

  /**
   * CsvExport constructor.
   *
   * @param \Drupal\labdoo_common\Service\Repository\ViewRepository $viewRepository
   */
  public function __construct(ViewRepository $viewRepository) {
    $this->viewRepository = $viewRepository;
  }

  /**
   * Exports view data into CSV.
   *
   * @param string $viewId
   *   The view ID.
   * @param string $displayId
   *   The display ID.
   *
   * @return string
   *   The CSV content.
   */
  public function export(string $viewId, string $displayId): string {
    $results = $this->viewRepository->getResults($viewId, $displayId);

    $csvData = [];
    $csvData[] = [
      'title',
      'created',
      'country',
      'hub',
      'needed',
      'delivered',
      'in transit',
      'remaining'
    ];

    foreach ($results as $row) {
      $edooVillage = $row->_entity;
      $created = new \DateTime();
      $created->setTimestamp($edooVillage->getCreatedTime());
      $csvData[] = [
        $edooVillage->label(),
        $created->format('Y-m-d'),
        $edooVillage->get('field_country')->value,
        $edooVillage->get('field_hub')->entity ? $edooVillage->get('field_hub')->entity->label() : '',
        $edooVillage->get('field_number_of_laptops_needed')->value,
        $edooVillage->get('field_dootronics_delivered')->value,
        $edooVillage->get('field_dootronics_in_transit')->value,
        $edooVillage->get('field_dootronics_remaining')->value,
      ];
    }

    $csvContent = '';
    foreach ($csvData as $csv_row) {
      $csvContent .= implode(',', $csv_row) . "\n";
    }

    return $csvContent;
  }

}
