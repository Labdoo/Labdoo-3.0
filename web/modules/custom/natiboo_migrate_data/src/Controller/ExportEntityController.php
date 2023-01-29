<?php

namespace Drupal\natiboo_migrate_data\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\natiboo_migrate_data\Service\EntityExport;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for exporting content.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ExportEntityController extends ControllerBase {

  /**
   * Constructs a new ExportEntityController instance.
   *
   * @param \Drupal\natiboo_migrate_data\Service\EntityExport $entityExport
   *   The content export service.
   * @param \Drupal\Core\Pager\PagerManagerInterface $pagerManager
   *   The pager manager service.
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   *   The form builder.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityExport $entityExport,
    protected PagerManagerInterface $pagerManager,
    FormBuilderInterface $formBuilder,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->formBuilder = $formBuilder;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('natiboo_migrate_data.entity.export'),
      $container->get('pager.manager'),
      $container->get('form_builder'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Route to list contents with export options and pagination.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Request object to manage filters and pagination.
   *
   * @return array
   *   Renderable array containing the listing table.
   */
  public function getEntitiesList(Request $request): array {
    $entityTypeIdFilter = (string) $request->get(
      'entity_type',
      'node'
    );
    $bundleFilter = (string) $request->get(
      'bundle',
      NULL
    );

    $header = [
      $this->t('Title'),
      $this->t('Entity type'),
      $this->t('Content Type'),
      $this->t('Operations'),
    ];

    $form = [];
    $rows = [];

    try {
      $entityQuery = $this->entityTypeManager()
        ->getStorage($entityTypeIdFilter)
        ->getQuery()
        ->accessCheck();

      if ($bundleFilter) {
        $entityQuery->condition('type', $bundleFilter);
      }

      // Add pagination logic.
      $itemsPerPage = 50;
      $totalItemsQuery = clone $entityQuery;
      $totalItems = $totalItemsQuery->count()->execute();
      $currentPage = $this->pagerManager->createPager($totalItems, $itemsPerPage)
        ->getCurrentPage();
      $entityQuery->range($currentPage * $itemsPerPage, $itemsPerPage);

      // Load the IDs of entities on the current page.
      $entityIds = $entityQuery->execute();
      $entityIds = array_values($entityIds);

      foreach ($entityIds as $nid) {
        $entity = $this->entityTypeManager
          ->getStorage('node')
          ->load($nid);
        if ($entity === NULL) {
          continue;
        }

        $url = Url::fromRoute(
          'natiboo_migrate_data.export_entity',
          [
            'entityTypeId' => $entity->getEntityTypeId(),
            'entityId' => $entity->id(),
          ]
        );

        $rows[] = [
          'data' => [
            $entity->label(),
            $entity->getEntityTypeId(),
            $entity->bundle(),
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

      $form = $this->formBuilder->getForm(
        'Drupal\natiboo_migrate_data\Form\FilterContentForm'
      );
    }
    catch (\Exception $e) {
      $this->getLogger('natiboo_migrate_data')->error(
        'Error loading entities: @message',
        ['@message' => $e->getMessage()]
      );

      $this->messenger()->addError($this->t(
        'An unexpected error occurred while loading entities. Please check the logs for more details.'
      ));
    }

    return [
      'filter_form' => $form,
      'content_table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

  /**
   * Route to export a specific content.
   *
   * @param string $entityTypeId
   *   The entity type ID.
   * @param int $entityId
   *   The ID of the node to export.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response of the exported node data.
   *
   * @throws \Exception
   */
  public function exportContent(string $entityTypeId, int $entityId): JsonResponse {
    $contentData = $this->entityExport->export($entityTypeId, $entityId);

    return new JsonResponse($contentData);
  }

}
