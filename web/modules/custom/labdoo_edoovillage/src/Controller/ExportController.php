<?php

namespace Drupal\labdoo_edoovillage\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\labdoo_edoovillage\Service\Export\CsvExport;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports the content of a view in CSV format.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ExportController extends ControllerBase {

  /**
   * The Csv Export.
   *
   * @var \Drupal\labdoo_edoovillage\Service\Export\CsvExport
   */
  protected CsvExport $csvExport;

  /**
   * ExportController constructor.
   *
   * @param \Drupal\labdoo_edoovillage\Service\Export\CsvExport $csvExport
   *   The CSV export.
   */
  public function __construct(CsvExport $csvExport) {
    $this->csvExport = $csvExport;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    /** @var \Drupal\labdoo_edoovillage\Service\Export\CsvExport $csvExport */
    $csvExport = $container->get('labdoo_edoovillage.export.csv');

    return new static(
      $csvExport
    );
  }

  /**
   * Exports view data into CSV.
   *
   * @param string $viewId
   *   The view ID.
   * @param string $displayId
   *   The diplay ID.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   The HTTP response with the CSV content.
   */
  public function exportCSV(string $viewId, string $displayId = 'default'): StreamedResponse {
    $response = new StreamedResponse(function () use ($viewId, $displayId) {
      $this->csvExport->streamExport($viewId, $displayId);
    });
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="edoovillages.csv"');

    return $response;
  }

}
