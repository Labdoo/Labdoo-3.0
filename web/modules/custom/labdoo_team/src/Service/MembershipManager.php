<?php

namespace Drupal\labdoo_team\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\labdoo_common\Event\InvalidateCacheTagsEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service to handle team membership operations.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MembershipManager {

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request|null $currentRequest
   */
  protected ?Request $currentRequest;

  /**
   * TeamMembershipManager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   */
  public function __construct(
    protected EntityTypeManager $entityTypeManager,
    protected AccountInterface $currentUser,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    RequestStack $requestStack,
    protected EventDispatcherInterface $eventDispatcher
  ) {
    $this->logger = $loggerChannelFactory->get('labdoo_team');
    $this->currentRequest = $requestStack->getCurrentRequest();
  }

  /**
   * Joins a team by adding the current user to the team members list.
   *
   * @param int $teamId
   *   The ID of the team to join.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function join(int $teamId): void {
    $userId = $this->currentUser->id();
    $team = $this->load($teamId);
    $team->get('field_team_members')->appendItem($userId);
    $team->save();

    $this->clearCache($teamId, $userId);
}

  /**
   * Leave a team by removing the current user from the team members.
   *
   * @param int $teamId
   *   The ID of the team from which the user wants to leave.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function leave(int $teamId): void {
    $userId = $this->currentUser->id();
    $team = $this->load($teamId);
    $members = $team->get('field_team_members')->getValue();

    foreach ($members as $key => $member) {
      if ($member['target_id'] == $userId) {
        unset($members[$key]);
      }
    }

    $team->set('field_team_members', $members);
    $team->save();

    $this->clearCache($teamId, $userId);
  }

  /**
   * Check if the current user belongs to a specific team group.
   *
   * @param int $teamId
   *   The ID of the team group to check.
   *
   * @return bool
   *   TRUE if the current user belongs to the team group, FALSE otherwise.
   */
  public function checkUserMembership(int $teamId): bool {
    $uid = $this->currentUser->id();

    try {
      $team = $this->load($teamId);
    } catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $errorMessage = sprintf(
        'Could not check if the user %d belongs to the team %d: %s',
        $uid,
        $teamId,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return FALSE;
    }

    $members = $team->get('field_team_members')->getValue();

    foreach ($members as $member) {
      if ($member['target_id'] == $uid) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Load a team entity by team ID.
   *
   * @param int $teamId
   *   The ID of the team entity to load.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The loaded team entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function load(int $teamId): EntityInterface {
    return $this->entityTypeManager
      ->getStorage('node')
      ->load($teamId);
  }

  /**
   * Build a join link for a team.
   *
   * @param int $teamId
   *   The ID of the team to join.
   * @param array $attributes
   *   An associative array of HTML attributes for the link (Optional).
   *
   * @return \Drupal\Core\Link
   *   The generated link to join the team.
   */
  public function buildJoinLink(
    int $teamId,
    array $attributes = []
  ): Link {
    return $this->buildLink(
      $teamId,
      'labdoo_team.join',
      'Subscribe to team',
      $attributes
    );
  }

  /**
   * Build a link to leave a team.
   *
   * @param int $teamId
   *   The ID of the team.
   *
   * @param array $attributes
   *   (Optional) An array of attributes to be added to the link.
   *
   * @return \Drupal\Core\Link
   *   The generated link to leave the team.
   */
  public function buildLeaveLink(int $teamId, array $attributes = []): Link {
    return $this->buildLink(
      $teamId,
      'labdoo_team.leave',
      'Leave the team',
      $attributes
    );
  }

  /**
   * Clear cache by invalidating cache tags for a specific team and user.
   *
   * @param int $teamId
   *   The ID of the team.
   * @param int $userId
   *   The ID of the user.
   */
  protected function clearCache(int $teamId, int $userId): void {
    $tag = sprintf(
      'team:%d:%d',
      $teamId,
      $userId
    );
    $event = new InvalidateCacheTagsEvent();
    $event->setCacheTags([$tag]);
    $this->eventDispatcher->dispatch(
      $event,
      InvalidateCacheTagsEvent::EVENT_NAME
    );
  }

  /**
   * Build a link to a specific route for a team entity.
   *
   * @param int $teamId
   *   The ID of the team entity used in the route.
   * @param string $route
   *   The name of the route for the link.
   * @param string $label
   *   The text to display for the link.
   * @param array $attributes
   *   Additional attributes for the link (optional).
   *
   * @return \Drupal\Core\Link
   *   The link generated for the team entity.
   */
  protected function buildLink(
    int $teamId,
    string $route,
    string $label,
    array $attributes = []
  ): Link {
    $url = Url::fromRoute(
      $route,
      ['teamId' => $teamId],
      ['query' => [
        'destination' => $this->currentRequest->getRequestUri()],
      ]
    );

    if (!empty($attributes)) {
      $existingOptions = $url->getOptions();
      $existingOptions['attributes'] = $attributes;
      $url->setOptions($existingOptions);
    }

    return Link::fromTextAndUrl($label, $url);
  }

}
