<?php

namespace Drupal\labdoo_dootronics\Controller;

use Drupal\Core\Cache\Cache;
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
   * LabelController constructor.
   *
   * @param \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository
   *   The dootronic repository.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(
    DootronicRepositoryInterface $dootronicRepository,
    RendererInterface $renderer
  ) {
    $this->dootronicRepository = $dootronicRepository;
    $this->renderer = $renderer;
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
      $renderer
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

}
