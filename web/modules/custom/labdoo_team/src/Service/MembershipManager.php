<?php

namespace Drupal\labdoo_team\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Link;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
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
  use StringTranslationTrait;

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
    $cacheTags = [
      sprintf(
        'team:%d:%d',
        $teamId,
        $userId
      ),
      sprintf('node:%d', $teamId),
    ];

    Cache::invalidateTags($cacheTags);

    $event = new InvalidateCacheTagsEvent();
    $event->setCacheTags($cacheTags);
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

  /**
   * Check if the global team is restricted for the current user.
   *
   * @param int|string|null $teamId
   *   The team ID to check.
   *
   * @return bool
   *   TRUE if it is the global team and the user is not a superhub manager.
   */
  public function isGlobalTeamRestricted($teamId): bool {
    return !$this->isAllowed((int) $teamId);
  }

  /**
   * Checks if a user is allowed to perform actions in a team.
   *
   * @param int|null $teamId
   *   The team ID.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   (Optional) The user account to check. Defaults to current user.
   *
   * @return bool
   *   TRUE if the user is allowed, FALSE otherwise.
   */
  public function isAllowed(?int $teamId, AccountInterface $account = NULL): bool {
    if (empty($teamId)) {
      return TRUE;
    }

    if (!$account) {
      $account = $this->currentUser;
    }

    $globalTeamId = defined('TEAM_GLOBAL') ? TEAM_GLOBAL : 24;

    if ((int) $teamId === (int) $globalTeamId) {
      return $account->hasRole('superhub_manager');
    }

    return TRUE;
  }

  /**
   * Checks if a user belongs to a team.
   *
   * @param int|null $teamId
   *   The team ID.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   (Optional) The user account to check. Defaults to current user.
   *
   * @return bool
   *   TRUE when user belongs to the team or team ID is empty, FALSE otherwise.
   */
  public function isMember(?int $teamId, AccountInterface $account = NULL): bool {
    if (empty($teamId)) {
      return TRUE;
    }

    if (!$account) {
      $account = $this->currentUser;
    }

    $userId = (int) $account->id();
    if ($userId <= 0) {
      return FALSE;
    }

    try {
      $team = $this->load($teamId);
      if (
        empty($team)
        || $team->bundle() !== 'team'
        || !$team->hasField('field_team_members')
      ) {
        return FALSE;
      }

      $members = $team->get('field_team_members')->getValue();
      foreach ($members as $member) {
        if ((int) $member['target_id'] === $userId) {
          return TRUE;
        }
      }
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $this->logger->error(
        'Could not check team membership for user @uid in team @team_id: @error',
        [
          '@uid' => $userId,
          '@team_id' => $teamId,
          '@error' => $e->getMessage(),
        ]
      );
    }

    return FALSE;
  }

  /**
   * Checks if a user can comment on a team post.
   *
   * @param int|null $teamId
   *   The team ID.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   (Optional) The user account to check. Defaults to current user.
   *
   * @return bool
   *   TRUE if the user can comment, FALSE otherwise.
   */
  public function canCommentOnTeamPost(?int $teamId, AccountInterface $account = NULL): bool {
    if (empty($teamId)) {
      return TRUE;
    }

    return $this->isAllowed($teamId, $account) && $this->isMember($teamId, $account);
  }

  /**
   * Get the team ID from a node if it is a team post or task.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return int|null
   *   The team ID or NULL if not found.
   */
  public function getTeamIdFromNode(NodeInterface $node): ?int {
    $types = ['team_post', 'task_team'];
    if (in_array($node->getType(), $types) && $node->hasField('field_team') && !$node->get('field_team')->isEmpty()) {
      return (int) $node->get('field_team')->target_id;
    }
    return NULL;
  }

}
