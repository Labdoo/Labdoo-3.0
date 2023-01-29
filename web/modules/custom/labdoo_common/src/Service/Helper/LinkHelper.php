<?php

namespace Drupal\labdoo_common\Service\Helper;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for managing entity links.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class LinkHelper {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request|null
   */
  protected ?Request $currentRequest;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * LinkHelper constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $currentRouteMatch
   *   The current route.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RouteMatchInterface $currentRouteMatch,
    protected AccountProxyInterface $currentUser,
    RequestStack $requestStack,
    LoggerChannelFactoryInterface $loggerChannelFactory
  ) {
    $this->currentRequest = $requestStack->getCurrentRequest();
    $this->logger = $loggerChannelFactory->get('labdoo_common');
  }

  /**
   * Generates the edit link of the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   *   The edit link of the given entity.
   */
  public function generateEditLink(EntityInterface $entity): GeneratedUrl|string {
    if ($entity->access('update', $this->currentUser) instanceof AccessResultAllowed) {
      return $this->generateUrlFromRoute(
        'entity.node.edit_form',
        ['node' => $entity->id()]
      );
    }

    if ($this->currentUser->isAuthenticated()) {
      return '';
    }

    $urlObject = Url::fromRoute(
      'entity.node.edit_form',
      ['node' => $entity->id()]
    );
    $url = $urlObject->toString();

    $urlObject = Url::fromRoute(
      'user.login',
      [],
      [
        'query' => [
          'destination' => $url,
        ],
      ]
    );

    return $urlObject->toString();
  }

  /**
   * Generates the revisions' link of the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   *   The revisions' link of the given entity.
   */
  public function generateRevisionLink(EntityInterface $entity): GeneratedUrl|string {
    return $this->generateUrlFromRoute(
      'entity.node.version_history',
      ['node' => $entity->id()]
    );
  }

  /**
   * Generates the clone link of the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   *   The clone link of the given entity.
   */
  public function generateCloneLink(EntityInterface $entity): GeneratedUrl|string {
    $roles = $this->currentUser->getRoles();
    if (
      !in_array('superhub_manager', $roles)
      && !in_array('administrator', $roles)
    ) {
      return '';
    }

    return $this->generateUrlFromRoute(
      'quick_node_clone.node.quick_clone',
      ['node' => $entity->id()]
    );
  }

  /**
   * Generates the pick-me-up link of the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param int $status
   *   The "pick me up" status.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   *   The "pick me up" link of the given entity.
   */
  public function generatePickMeUpLink(EntityInterface $entity, int $status): GeneratedUrl|string {
    if (!$entity->access('update', $this->currentUser)) {
      return '';
    }

    return $this->generateUrlFromRoute(
      'labdoo_dootronics.pick_me_up',
      [
        'dootronicId' => $entity->id(),
        'status' => $status,
      ]
    );
  }

  /**
   * Generates the wall link of the given team.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   *   The "wall" link of the given entity.
   */
  public function generateWallLink(EntityInterface $entity): GeneratedUrl|string {
    return $this->generateUrlFromRoute(
      'view.team_wall.page_1',
      [
        'arg_0' => $entity->id(),
      ]
    );
  }

  /**
   * Generates the link of the tasks' list for the given team.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   *   The generated link.
   */
  public function generateTasksByTeamLink(EntityInterface $entity): GeneratedUrl|string {
    return $this->generateUrlFromRoute(
      'view.team_tasks.page_1',
      [
        'arg_0' => $entity->id(),
      ]
    );
  }

  /**
   * Generates the team post link.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   *   The "team post" link of the given entity.
   */
  public function generateTeamPostLink(EntityInterface $entity): GeneratedUrl|string {
    return $this->generateUrlFromRoute(
      'node.add',
      [
        'node_type' => 'team_post',
        [
          'team_id' => $entity->id(),
          'destination' => $this->currentRequest->getRequestUri(),
        ]
      ]
    );
  }

  /**
   * Generates the team members link.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   *   The "team members" link of the given entity.
   */
  public function generateTeamMembersLink(EntityInterface $entity): GeneratedUrl|string {
    return $this->generateUrlFromRoute(
      'view.team_members.page_1',
      [
        'arg_0' => $entity->id(),
      ]
    );
  }

  /**
   * Generates a URL from the given route.
   *
   * @param string $routeName
   *   The route name.
   * @param array $parameters
   *   The parameters' array.
   * @param array $options
   *   The options' array.
   *
   * @return \Drupal\Core\GeneratedUrl|string
   */
  public function generateUrlFromRoute(
    string $routeName,
    array $parameters = [],
    array $options = []
  ): GeneratedUrl|string {
    $urlObject = Url::fromRoute($routeName, $parameters, $options);
    $url = $urlObject->toString();
    if ($urlObject->access($this->currentUser)) {
      return $urlObject->toString();
    }

    $urlObject = Url::fromRoute(
      'user.login',
      [],
      [
        'query' => [
          'destination' => $url,
        ],
      ]
    );

    return $urlObject->toString();
  }

  /**
   * Retrieves the active node.
   *
   * @param string $paramName
   *   The parameter name.
   *
   * @return mixed|null
   *   The active node.
   */
  public function getActiveNode(string $paramName = 'node') {
    $parameters = $this->currentRouteMatch->getParameters()->all();
    if (isset($parameters[$paramName])) {
      return $parameters[$paramName];
    }

    return NULL;
  }

  /**
   * Loads an entity by its ID.
   *
   * @param int $entityId
   *   The ID of the entity to load.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The loaded entity, or NULL if the entity could not be loaded.
   */
  public function loadEntity(int $entityId): ?EntityInterface {
    try {
      return $this->entityTypeManager
        ->getStorage('node')
        ->load($entityId);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $this->logger->error(
        'Error loading entity @entity_id: @error',
        [
          '@entity_id' => $entityId,
          '@error' => $e->getMessage(),
        ]
      );

      return NULL;
    }
  }

}
