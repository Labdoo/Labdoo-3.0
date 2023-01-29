<?php

namespace Drupal\labdoo_dootrip\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\labdoo_dootrip\Service\Export\CsvExport;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

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
   * @var \Drupal\labdoo_dootrip\Service\Export\CsvExport
   */
  protected CsvExport $csvExport;

  public function __construct(CsvExport $csvExport) {
    $this->csvExport = $csvExport;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    /** @var \Drupal\labdoo_dootrip\Service\Export\CsvExport $csvExport */
    $csvExport = $container->get('labdoo_dootrip.export.csv');

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
   * @return \Symfony\Component\HttpFoundation\Response
   *   The HTTP response with the CSV content.
   */
  public function exportCSV(string $viewId, string $displayId = 'default'): Response {
    $response = new Response($this->csvExport->export($viewId, $displayId));
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment; filename="dootrips.csv"');

    return $response;
  }

}
