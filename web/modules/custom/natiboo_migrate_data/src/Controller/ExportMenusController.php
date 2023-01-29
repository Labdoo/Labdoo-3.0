<?php

namespace Drupal\natiboo_migrate_data\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\natiboo_migrate_data\Service\MenuExport;

/**
 * Controller for exporting menus.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ExportMenusController extends ControllerBase {

  /**
   * Constructs a new ExportMenusController instance.
   *
   * @param \Drupal\natiboo_migrate_data\Service\MenuExport $menuExportService
   *   The menu export service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected MenuExport $menuExportService,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('natiboo_migrate_data.menu.export'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Route to list menus with export options.
   *
   * @return array
   *   Renderable array containing the listing table.
   */
  public function getMenusList(): array {
    $header = [
      $this->t('Menu Name'),
      $this->t('Operations'),
    ];
    $rows = [];

    try {
      $menus = $this->entityTypeManager
        ->getStorage('menu')
        ->loadMultiple();

      foreach ($menus as $menu) {
        $url = Url::fromRoute(
          'natiboo_migrate_data.export_menu',
          ['menuName' => $menu->id()]
        );

        $rows[] = [
          'data' => [
            $menu->label(),
            [
              'data' => [
                '#type' => 'link',
                '#title' => $this->t('Export'),
                '#url' => $url,
                '#attributes' => [
                  'class' => ['button', 'button--small'],
                ],
              ],
            ],
          ],
        ];
      }
    }
    catch (\Exception $e) {
      $this->getLogger('natiboo_migrate_data')->error('Error loading menus: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('An unexpected error occurred while loading menus. Please check the logs for more details.'));
    }

    // Render table with menus.
    return [
      'menu_table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
      ],
    ];
  }

  /**
   * Route to export a specific menu and force file download.
   *
   * @param string $menuName
   *   The machine name of the menu to export.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Response with the file download.
   */
  public function exportMenu(string $menuName): Response {
    try {
      $menuData = $this->menuExportService->export($menuName);
    }
    catch (\Exception $e) {
      $this->getLogger('natiboo_migrate_data')->error(
        'Error exporting menu "@menu_name": @message',
        [
          '@menu_name' => $menuName,
          '@message' => $e->getMessage(),
        ]
      );

      $this->messenger()->addError(
        $this->t('An error occurred while exporting the menu. Please contact an administrator.')
      );

      return new Response(
        $this->t('Failed to export the menu. An internal server error occurred.'),
        Response::HTTP_INTERNAL_SERVER_ERROR
      );
    }

    // Generate a filename (e.g., "menu-{machine_name}.json").
    $filename = "menu-{$menuName}.json";

    // Convert menu data to a JSON string.
    $jsonOutput = json_encode(
      $menuData,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );

    if ($jsonOutput === FALSE) {
      $this->getLogger('natiboo_migrate_data')->error(
        'Failed to convert menu data to JSON.'
      );
      $jsonOutput = '';
    }

    // Create a response object with download headers.
    $response = new Response($jsonOutput);
    $response->headers->set('Content-Type', 'application/json');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Content-Length', (string) strlen($jsonOutput));

    return $response;
  }

}
