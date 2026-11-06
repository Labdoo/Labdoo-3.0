<?php

namespace Drupal\labdoo_dootronics\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityStorageInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\labdoo_dootronics\Model\DootronicLabelModel;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller that prints labels.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class LabelController extends ControllerBase {

  /**
   * The dootronic repository.
   *
   * @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface
   */
  protected DootronicRepositoryInterface $dootronicRepository;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * The node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $nodeStorage;

  /**
   * LabelController constructor.
   *
   * @param \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository
   *   The dootronic repository.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Entity\EntityStorageInterface $nodeStorage
   *   The node storage.
   */
  public function __construct(
    DootronicRepositoryInterface $dootronicRepository,
    RendererInterface $renderer,
    EntityStorageInterface $nodeStorage
  ) {
    $this->dootronicRepository = $dootronicRepository;
    $this->renderer = $renderer;
    $this->nodeStorage = $nodeStorage;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    /** @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository */
    $dootronicRepository = $container->get('labdoo_dootronics.repository');
    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = $container->get('renderer');

    return new static(
      $dootronicRepository,
      $renderer,
      $container->get('entity_type.manager')->getStorage('node')
    );
  }

  /**
   * Prints the dootronic labels.
   *
   * @param string $startingDootronicId
   *   The starting dootronic ID.
   * @param string $endingDootronicId
   *   The ending dootronic ID.
   *
   * @return array
   *   The build array.
   */
  public function printLabels(
    string $startingDootronicId,
    string $endingDootronicId = ''
  ): array {
    $dootronic = $this->dootronicRepository->load($startingDootronicId);

    if (empty($endingDootronicId)) {
      // Fallback to label.
      if (!$dootronic) {
        $dootronic = $this->dootronicRepository->loadByLabel($startingDootronicId);
      }

      if (!$dootronic) {
        throw new NotFoundHttpException();
      }

      $dootronicLabelModel = new DootronicLabelModel(
        $dootronic->label(),
        $this->dootronicRepository->generateQrCode($dootronic),
        $this->dootronicRepository->computeWattHours($dootronic)
      );

      $cacheTags = [
        sprintf('dootronic:%d', $dootronic->id()),
      ];

      return [
        '#theme' => 'dootronics_label',
        '#dootronics_collection' => [$dootronicLabelModel],
        '#cache' => [
          'max-age' => Cache::PERMANENT,
          'contexts' => [
            'url.path',
            'session',
          ],
          'tags' => $cacheTags,
        ],
      ];
    }

    // Fallback to label.
    if (!$dootronic) {
      $startingDootronic = $this->dootronicRepository->loadByLabel($startingDootronicId);
      if (!$startingDootronic) {
        throw new NotFoundHttpException();
      }
      $startingDootronicId = $startingDootronic->id();

      $endingDootronic = $this->dootronicRepository->loadByLabel($endingDootronicId);
      if (!$endingDootronic) {
        throw new NotFoundHttpException();
      }
      $endingDootronicId = $endingDootronic->id();
    }

    $dootronicsInRange = $this->dootronicRepository->getDootronicsInRange(
      $startingDootronicId,
      $endingDootronicId
    );
    $dootronicLabelModelCollection = [];
    $cacheTags = [];

    foreach ($dootronicsInRange as $dootronic) {
      $dootronicLabelModelCollection[] = new DootronicLabelModel(
        $dootronic->label(),
        $this->dootronicRepository->generateQrCode($dootronic),
        $this->dootronicRepository->computeWattHours($dootronic)
      );

      $cacheTags[] = sprintf('dootronic:%d', $dootronic->id());
    }

    return [
      '#theme' => 'dootronics_label',
      '#dootronics_collection' => $dootronicLabelModelCollection,
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'contexts' => [
          'url.path',
        ],
        'tags' => $cacheTags,
      ],
    ];
  }

  /**
   * Prints Labdoo IDs supplied as repeated id parameters or id_first/id_last.
   */
  public function printSelectedLabels(Request $request): array {
    $labels = [];
    foreach (explode('&', $request->server->get('QUERY_STRING', '')) as $pair) {
      [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
      if (in_array(urldecode($key), ['id', 'id[]'], TRUE)) {
        $labels[] = $this->normalizeLabdooId(urldecode($value));
      }
    }
    $first = $request->query->get('id_first');
    $last = $request->query->get('id_last');
    if ($labels && ($first !== NULL || $last !== NULL)) {
      throw new BadRequestHttpException('Use either a list of IDs or a range.');
    }
    $query = $this->nodeStorage->getQuery()
      ->accessCheck(TRUE)->condition('type', 'dootronic');
    if ($labels) {
      $labels = array_values(array_unique($labels));
      if (count($labels) > 1000) {
        throw new BadRequestHttpException('Print at most 1000 labels per request.');
      }
      $query->condition('title', $labels, 'IN');
    }
    else {
      $first = $this->normalizeLabdooId($first ?? '');
      $last = $this->normalizeLabdooId($last ?? '');
      if ($first > $last || (int) $last - (int) $first >= 1000) {
        throw new BadRequestHttpException('Use an ascending range of at most 1000 IDs.');
      }
      $query->condition('title', $first, '>=')->condition('title', $last, '<=');
      $query->sort('title');
    }
    $entities = $this->nodeStorage->loadMultiple($query->execute());
    $by_label = [];
    foreach ($entities as $entity) {
      $by_label[$entity->label()] = $entity;
    }
    if ($labels) {
      $entities = [];
      foreach ($labels as $label) {
        if (!isset($by_label[$label])) {
          throw new NotFoundHttpException('A selected dootronic was not found.');
        }
        $entities[] = $by_label[$label];
      }
    }
    if (!$entities) {
      throw new NotFoundHttpException('No dootronics were found.');
    }
    $build = [
      '#theme' => 'dootronics_label',
      '#dootronics_collection' => [],
      '#cache' => [
        // Normalized query cache contexts collapse repeated id parameters.
        'max-age' => 0,
        'contexts' => ['url.query_args', 'user.permissions'],
        'tags' => ['node_list'],
      ],
    ];
    $cache = CacheableMetadata::createFromRenderArray($build);
    foreach ($entities as $entity) {
      $access = $entity->access('view', NULL, TRUE);
      $cache->addCacheableDependency($entity)->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        throw new AccessDeniedHttpException();
      }
      $build['#dootronics_collection'][] = new DootronicLabelModel(
        $entity->label(),
        $this->dootronicRepository->generateQrCode($entity),
        $this->dootronicRepository->computeWattHours($entity)
      );
    }
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Normalizes a public Labdoo ID, rather than a Drupal node ID.
   */
  protected function normalizeLabdooId(string $value): string {
    if (!preg_match('/^[0-9]{1,9}$/D', $value) || (int) $value === 0) {
      throw new BadRequestHttpException('A Labdoo ID must contain one to nine digits.');
    }
    return str_pad($value, 9, '0', STR_PAD_LEFT);
  }

}
