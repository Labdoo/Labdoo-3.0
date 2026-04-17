<?php

namespace Drupal\labdoo_team\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\labdoo_common\Service\Helper\LinkHelper;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_team\Service\MembershipManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Team actions' block.
 *
 * @Block(
 *   id = "team_actions_block_block",
 *   admin_label = @Translation("Team actions"),
 *   category = @Translation("Team"),
 * )
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class TeamActionsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * TeamActionsBlock constructor.
   *
   * @param array $configuration
   *   The configuration array.
   * @param mixed $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\labdoo_common\Service\Helper\LinkHelper $linkHelper
   *   The link helper.
   * @param \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository
   *   The common repository.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\labdoo_team\Service\MembershipManager $teamMembershipManager
   *   The team membership manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected LinkHelper $linkHelper,
    protected CommonRepository $commonRepository,
    protected AccountProxyInterface $currentUser,
    protected MembershipManager $teamMembershipManager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @codeCoverageIgnore
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    /** @var \Drupal\labdoo_common\Service\Helper\LinkHelper $linkHelper */
    $linkHelper = $container->get('labdoo_common.helper.link');
    /** @var \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository */
    $commonRepository = $container->get('labdoo_common.repository.common');
    /** @var \Drupal\Core\Session\AccountProxyInterface $currentUser */
    $currentUser = $container->get('current_user');
    /** @var \Drupal\labdoo_team\Service\MembershipManager $teamMembershipManager */
    $teamMembershipManager = $container->get('labdoo_team.membership.manager');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $linkHelper,
      $commonRepository,
      $currentUser,
      $teamMembershipManager
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $team = $this->linkHelper->getActiveNode();
    if (!$this->isValidTeam($team)) {
      $teamId = $this->linkHelper->getActiveNode('arg_0');
      if (empty($teamId)) {
        return [
          '#markup' => '',
        ];
      }

      $team = $this->commonRepository->loadEntity($teamId);
      if (!$this->isValidTeam($team)) {
        return [
          '#markup' => '',
        ];
      }
    }

    if ($team->bundle() === 'team_post') {
      $team = $team->get('field_team')->entity;
    }

    if (empty($team)) {
      return [
        '#markup' => '',
      ];
    }

    $editLink = $this->linkHelper->generateEditLink($team);
    $wallLink = $this->linkHelper->generateWallLink($team);

    $isMember = $this->teamMembershipManager->checkUserMembership($team->id());
    if (!$isMember) {
      $membershipLink = $this->teamMembershipManager->buildJoinLink($team->id());
      $postLink = '';
      $membersLink = '';
      $tasksLink = '';
    }
    else {
      $membershipLink = $this->teamMembershipManager->buildLeaveLink($team->id());
      $postLink = $this->linkHelper->generateTeamPostLink($team);
      $tasksLink = $this->linkHelper->generateTasksByTeamLink($team);
      if (
        (int) $team->id() === TEAM_GLOBAL
        && !$this->currentUser->hasRole('superhub_manager')
      ) {
        $postLink = '';
        $tasksLink = '';
      }
      $membersLink = $this->linkHelper->generateTeamMembersLink($team);
    }

    $cacheTags = [
      sprintf(
        'team:%d:%d',
        $team->id(),
        $this->currentUser->id()
      ),
    ];

    return [
      '#theme' => 'team_actions_block_block',
      '#team_name' => $team->label(),
      '#edit_link' => $editLink,
      '#wall_link' => $wallLink,
      '#membership_link' => $membershipLink->getUrl(),
      '#membership_label' => $membershipLink->getText(),
      '#post_link' => $postLink,
      '#members_link' => $membersLink,
      '#tasks_link' => $tasksLink,
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

  /**
   * Validates if the given team is valid.
   *
   * @param mixed $team
   *   The team entity to be validated.
   *
   * @return bool
   *   TRUE if the team is valid, FALSE otherwise.
   */
  protected function isValidTeam($team): bool {
    return !empty($team) && ($team->bundle() === 'team' || $team->bundle() === 'team_post');
  }

}
