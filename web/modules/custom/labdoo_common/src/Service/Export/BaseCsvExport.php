<?php

namespace Drupal\labdoo_common\Service\Export;

use Drupal\labdoo_common\Service\Repository\ViewRepository;

/**
 * Base class for CSV exports from views.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
abstract class BaseCsvExport {

  /**
   * The view repository.
   *
   * @var \Drupal\labdoo_common\Service\Repository\ViewRepository
   */
  protected ViewRepository $viewRepository;

  /**
   * BaseCsvExport constructor.
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
    $csvData[] = $this->getHeader(); // Encabezado definido en subclase.

    foreach ($results as $row) {
      $entity = $row->_entity;
      $csvData[] = $this->getRowData($entity); // Datos de fila definidos en subclase.
    }

    $csvContent = '';
    foreach ($csvData as $csv_row) {
      $csvContent .= implode(',', $csv_row) . "\n";
    }

    return $csvContent;
  }

  /**
   * Gets the CSV header row.
   *
   * @return array
   *   Array of header strings.
   */
  abstract protected function getHeader(): array;

  /**
   * Gets the data for a single entity row.
   *
   * @param object $entity
   *   The entity object.
   *
   * @return array
   *   Array of row data.
   */
  abstract protected function getRowData(object $entity): array;

}
