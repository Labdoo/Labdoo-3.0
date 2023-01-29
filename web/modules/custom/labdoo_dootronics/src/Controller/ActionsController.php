<?php

namespace Drupal\labdoo_dootronics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Dootronics' actions.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ActionsController extends ControllerBase {

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
   * Renders a dootronic by label.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $dootronicLabel
   *   The dootronic label.
   *
   * @return void
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function viewDootronic(Request $request, string $dootronicLabel): void {
    $dootronic = $this->dootronicRepository->loadByLabel($dootronicLabel);
    if ($dootronic === NULL) {
      $errorMessage = t(
        'Dootronic @dootronic_id not found',
        [
          '@dootronic_id' => $dootronicLabel,
        ]
      );
      $this->messenger()->addError($errorMessage);

      $response = new RedirectResponse($request->getRequestUri());
      $response->send();

      die;
    }

    $entityUrl = $dootronic->toUrl()->toString();
    $response = new RedirectResponse($entityUrl);
    $response->send();

    die;
  }

  /**
   * Follows a dootronic.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param int $dootronicId
   *   The dootronic label.
   *
   * @return void
   */
  public function followDootronic(Request $request, int $dootronicId): void {
    $dootronic = $this->loadDootronic($request, $dootronicId);

    if (!$this->dootronicRepository->follow($dootronic)) {
      $errorMessage = t(
        'The dootronic @dootronic_id could not be followed',
        [
          '@dootronic_id' => $dootronicId,
        ]
      );
      $this->messenger()->addError($errorMessage);

      $entityUrl = $dootronic->toUrl()->toString();
      $response = new RedirectResponse($entityUrl);
      $response->send();

      die;
    }

    $message = t(
      'You are now following Dootronic @dootronic_id',
      [
        '@dootronic_id' => $dootronic->label(),
      ]
    );
    $this->messenger()->addStatus($message);

    $entityUrl = $dootronic->toUrl()->toString();
    $response = new RedirectResponse($entityUrl);
    $response->send();

    die;
  }

  /**
   * Unfollows a dootronic.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param int $dootronicId
   *   The dootronic label.
   *
   * @return void
   *
   */
  public function unfollowDootronic(Request $request, int $dootronicId): void {
    $dootronic = $this->loadDootronic($request, $dootronicId);

    if (!$this->dootronicRepository->unfollow($dootronic)) {
      $errorMessage = t(
        'The dootronic @dootronic_id could not be unfollowed',
        [
          '@dootronic_id' => $dootronicId,
        ]
      );
      $this->messenger()->addError($errorMessage);

      $entityUrl = $dootronic->toUrl()->toString();
      $response = new RedirectResponse($entityUrl);
      $response->send();

      die;
    }

    $message = t(
      'You are no longer following Dootronic @dootronic_id',
      [
        '@dootronic_id' => $dootronic->label(),
      ]
    );
    $this->messenger()->addStatus($message);

    $entityUrl = $dootronic->toUrl()->toString();
    $response = new RedirectResponse($entityUrl);
    $response->send();

    die;
  }

  /**
   * Toggles the "pick me up" status.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param int $dootronicId
   *   The dootronic label.
   * @param int $status
   *   The "pick me up" status.
   *
   * @return void
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function togglePickMeUp(Request $request, int $dootronicId, int $status): void {
    $dootronic = $this->loadDootronic($request, $dootronicId);

    if (!$this->dootronicRepository->togglePickMeUp($dootronic, $status)) {
      $errorMessage = t(
        'The dootronic @dootronic_id pick me up status could not be updated',
        [
          '@dootronic_id' => $dootronicId,
        ]
      );
      $this->messenger()->addError($errorMessage);

      $entityUrl = $dootronic->toUrl()->toString();
      $response = new RedirectResponse($entityUrl);
      $response->send();

      die;
    }

    $entityUrl = $dootronic->toUrl()->toString();
    $response = new RedirectResponse($entityUrl);
    $response->send();

    die;
  }

  /**
   * Loads a dootronic and dies in case of error.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param int $dootronicId
   *   The dootronic ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The loaded dootronic.
   */
  protected function loadDootronic(Request $request, int $dootronicId): EntityInterface {
    $dootronic = $this->dootronicRepository->load($dootronicId);
    if ($dootronic !== NULL) {
      return $dootronic;
    }

    $errorMessage = t(
      'Dootronic @dootronic_id not found',
      [
        '@dootronic_id' => $dootronicId,
      ]
    );
    $this->messenger()->addError($errorMessage);

    $response = new RedirectResponse($request->getRequestUri());
    $response->send();

    die;
  }

}
