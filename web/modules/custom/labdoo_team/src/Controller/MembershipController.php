<?php

namespace Drupal\labdoo_team\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\labdoo_team\Service\MembershipManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller to handle team membership operations.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MembershipController extends ControllerBase {

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * TeamMembershipController constructor.
   *
   * @param \Drupal\labdoo_team\Service\MembershipManager $teamMembershipManager
   *   The team membership manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   */
  public function __construct(
    protected MembershipManager $teamMembershipManager,
    LoggerChannelFactoryInterface $loggerChannelFactory
  ) {
    $this->logger = $loggerChannelFactory->get('labdoo_team');
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('labdoo_team.membership.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * Allows a user joining a team.
   *
   * @param int $teamId
   *   The team ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirection.
   */
  public function join(int $teamId): RedirectResponse {
    try {
      $this->teamMembershipManager->join($teamId);

      $this->messenger()->addStatus($this->t(
        'You have joined to the team.'
      ));
    } catch (\Exception $e) {
      $errorMessage = sprintf(
        'There was an error joining to the team %d: %s',
        $teamId,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      $this->messenger()->addError($this->t(
        'There was an error joining to the team. Please try again later.'
      ));
    }

    return new RedirectResponse(
      Url::fromRoute('view.teams.page_1')->toString()
    );
  }

  /**
   * Allows a user leaving a team.
   *
   * @param int $teamId
   *   The team ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirection.
   */
  public function leave(int $teamId): RedirectResponse {
    try {
      $this->teamMembershipManager->leave($teamId);

      $this->messenger()->addStatus($this->t(
        'You have left the team.'
      ));
    } catch (\Exception $e) {
      $errorMessage = sprintf(
        'There was an error leaving the team %d: %s',
        $teamId,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      $this->messenger()->addError($this->t(
        'There was an error leaving the team. Please try again later.'
      ));
    }

    return new RedirectResponse(
      Url::fromRoute('view.teams.page_1')->toString()
    );
  }

}
