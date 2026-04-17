<?php

namespace Drupal\labdoo_hub\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\labdoo_common\Service\Helper\LinkHelper;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_gallery\Services\LabdooGalleryRepositoryInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Hub actions' block.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Block(
 *   id = "hub_actions_block_block",
 *   admin_label = @Translation("Hub actions"),
 *   category = @Translation("Hub"),
 * )
 */
class HubActionsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * HubActionsBlock constructor.
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
   * @param \Drupal\labdoo_gallery\Services\LabdooGalleryRepositoryInterface $galleryRepository
   *   The gallery repository.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LinkHelper $linkHelper,
    CommonRepository $commonRepository,
    AccountProxyInterface $currentUser,
    protected LabdooGalleryRepositoryInterface $galleryRepository,
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
    /** @var \Drupal\labdoo_gallery\Services\LabdooGalleryRepositoryInterface $galleryRepository */
    $galleryRepository = $container->get('labdoo_gallery.repository');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $linkHelper,
      $commonRepository,
      $currentUser,
      $galleryRepository,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $hub = $this->linkHelper->getActiveNode();

    // Fallback for arg_0 (views).
    if (!($hub instanceof NodeInterface) || $hub->bundle() !== 'hub') {
      $entityId = $this->linkHelper->getActiveNode('arg_0');
      if ($entityId) {
        $hub = $this->linkHelper->loadEntity($entityId);
      }
    }

    if ($hub instanceof NodeInterface && $hub->bundle() === 'hub') {
      return AccessResult::allowed()->addCacheContexts(['url.path']);
    }

    return AccessResult::forbidden()->addCacheContexts(['url.path']);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $hub = $this->linkHelper->getActiveNode();

    // Fallback for arg_0 (views) or specific routes.
    if (!($hub instanceof NodeInterface) || $hub->bundle() !== 'hub') {
      $entityId = $this->linkHelper->getActiveNode('arg_0');
      // If it's not in arg_0, maybe it's in the route parameters as % (it depends on the route definition)
      // but views usually use arg_0 for the first contextual filter.
      if ($entityId) {
        $hub = $this->linkHelper->loadEntity($entityId);
      }
    }

    if (!($hub instanceof NodeInterface) || $hub->bundle() !== 'hub') {
      return [
        '#markup' => '',
      ];
    }

    $editLink = $this->linkHelper->generateEditLink($hub);
    $revisionsLink = $this->linkHelper->generateRevisionLink($hub);
    $cloneLink = $this->linkHelper->generateCloneLink($hub);
    $semaphore = $hub->get('field_hub_status')->value;

    $prevNode = $this->commonRepository->getPreviousEntity(
      $hub->id(),
      $hub->bundle()
    );
    $prevLink = $this->linkHelper->generateUrlFromRoute(
      'entity.node.canonical',
      ['node' => $prevNode],
    );

    $nextNode = $this->commonRepository->getNextEntity(
      $hub->id(),
      $hub->bundle()
    );
    $nextLink = $this->linkHelper->generateUrlFromRoute(
      'entity.node.canonical',
      ['node' => $nextNode],
    );

    $hubType = $hub->get('field_types_of_mini_missions')->getValue();
    $dropping = '';
    $sanitation = '';
    foreach ($hubType as $type) {
      if ($type['value'] === 'HUBTYPE0') {
        $dropping = TRUE;
      }
      elseif ($type['value'] === 'HUBTYPE1') {
        $sanitation = TRUE;
      }
    }

    $gallery = $this->galleryRepository->loadByParentId($hub->id());
    $galleryLink = '';
    if ($gallery !== NULL) {
      $galleryLink = $this->linkHelper->generateUrlFromRoute(
        'entity.node.canonical',
        ['node' => $gallery->id()],
      );
    }

    $storyNode = $this->commonRepository->getStory($hub->id());
    $writeStoryLink = '';
    $storyLink = '';
    if ($storyNode) {
      $storyLink = $this->linkHelper->generateUrlFromRoute(
        'entity.node.canonical',
        ['node' => $storyNode->id()],
      );
    }
    else {
      $writeStoryLink = $this->linkHelper->generateUrlFromRoute(
        'node.add',
        ['node_type' => 'labdoo_story'],
        [
          'query' => [
            'parent_id' => $hub->id(),
          ]
        ]
      );
    }

    $dootronicsLink = $this->linkHelper->generateUrlFromRoute(
      'view.dootronics_dashboard.page_3',
      ['arg_0' => $hub->id()],
    );

    $dootripsLink = $this->linkHelper->generateUrlFromRoute(
      'view.dootrips_dashboard.page_3',
      ['arg_0' => $hub->id()],
    );

    $dataLink = $this->linkHelper->generateUrlFromRoute(
      'entity.node.canonical',
      ['node' => $hub->id()],
    );

    $cacheTags = [
      sprintf(
        'hub:%d:%d',
        $hub->id(),
        $this->currentUser->id()
      ),
    ];

    return [
      '#theme' => 'hub_actions_block_block',
      '#edit_link' => $editLink,
      '#photo_album_link' => $galleryLink,
      '#write_story_link' => $writeStoryLink,
      '#story_link' => $storyLink,
      '#revisions_link' => $revisionsLink,
      '#clone_link' => $cloneLink,
      '#next_link' => $nextLink,
      '#previous_link' => $prevLink,
      '#semaphore' => $semaphore,
      '#dropping' => $dropping,
      '#sanitation' => $sanitation,
      '#dootronics_link' => $dootronicsLink,
      '#dootrips_link' => $dootripsLink,
      '#data_link' => $dataLink,
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
