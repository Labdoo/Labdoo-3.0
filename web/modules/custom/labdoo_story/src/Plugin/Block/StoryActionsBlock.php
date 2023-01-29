<?php

namespace Drupal\labdoo_story\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\labdoo_common\Service\Helper\LinkHelper;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Story actions' block.
 *
 * @Block(
 *   id = "story_actions_block_block",
 *   admin_label = @Translation("Story actions"),
 *   category = @Translation("Story"),
 * )
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class StoryActionsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The link helper.
   *
   * @var \Drupal\labdoo_common\Service\Helper\LinkHelper
   */
  protected LinkHelper $linkHelper;

  /**
   * The common repository.
   *
   * @var \Drupal\labdoo_common\Service\Repository\CommonRepository
   */
  protected CommonRepository $commonRepository;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * StoryActionsBlock constructor.
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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LinkHelper $linkHelper,
    CommonRepository $commonRepository,
    AccountProxyInterface $currentUser
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->linkHelper = $linkHelper;
    $this->commonRepository = $commonRepository;
    $this->currentUser = $currentUser;
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

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $linkHelper,
      $commonRepository,
      $currentUser
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $story = $this->linkHelper->getActiveNode();
    if (!$story) {
      return [
        '#markup' => '',
      ];
    }

    $editLink = $this->linkHelper->generateEditLink($story);

    $prevNode = $this->commonRepository->getPreviousEntity(
      $story->id(),
      $story->bundle()
    );
    $prevLink = $this->linkHelper->generateUrlFromRoute(
      'entity.node.canonical',
      ['node' => $prevNode],
    );

    $nextNode = $this->commonRepository->getNextEntity(
      $story->id(),
      $story->bundle()
    );
    $nextLink = $this->linkHelper->generateUrlFromRoute(
      'entity.node.canonical',
      ['node' => $nextNode],
    );

    $parentNode = $story->get('field_parent')->entity;
    $parentLink = '';
    $parentTitle = '';
    $parentType = '';
    if ($parentNode) {
      $parentLink = $this->linkHelper->generateUrlFromRoute(
        'entity.node.canonical',
        ['node' => $parentNode->id()],
      );
      $parentTitle = $parentNode->label();
      $parentType = $parentNode->bundle();
    }

    $created = $story->get('created')->value;
    $formattedCreated = date('d/m/Y', $created);

    $updated = $story->get('changed')->value;
    $formattedUpdated = date('d/m/Y', $updated);

    $cacheTags = [
      sprintf(
        'edoovillage:%d:%d',
        $story->id(),
        $this->currentUser->id()
      ),
    ];

    return [
      '#theme' => 'story_actions_block_block',
      '#edit_link' => $editLink,
      '#next_link' => $nextLink,
      '#previous_link' => $prevLink,
      '#parent_link' => $parentLink,
      '#parent_title' => $parentTitle,
      '#parent_type' => $parentType,
      '#created' => $formattedCreated,
      '#updated' => $formattedUpdated,
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

}
