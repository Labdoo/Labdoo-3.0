<?php

namespace Drupal\labdoo_edoovillage\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\labdoo_common\Service\Helper\LinkHelper;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_gallery\Services\LabdooGalleryRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'EdooVillage actions' block.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Block(
 *   id = "edoovillage_actions_block_block",
 *   admin_label = @Translation("EdooVillage actions"),
 *   category = @Translation("EdooVillage"),
 * )
 */
class EdooVillageActionsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * EdooVillageActionsBlock constructor.
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
      $galleryRepository
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $edooVillage = $this->linkHelper->getActiveNode();

    // Fallback for arg_0 (views).
    if (!($edooVillage instanceof \Drupal\node\NodeInterface) || $edooVillage->bundle() !== 'edoovillage') {
      $entityId = $this->linkHelper->getActiveNode('arg_0');
      if ($entityId) {
        $edooVillage = $this->linkHelper->loadEntity($entityId);
      }
    }

    if (!($edooVillage instanceof \Drupal\node\NodeInterface) || $edooVillage->bundle() !== 'edoovillage') {
      return [
        '#markup' => '',
      ];
    }

    $editLink = $this->linkHelper->generateEditLink($edooVillage);
    $revisionsLink = $this->linkHelper->generateRevisionLink($edooVillage);
    $cloneLink = $this->linkHelper->generateCloneLink($edooVillage);
    $semaphore = $edooVillage->get('field_semaphore')->value;
    $status = $edooVillage->get('field_status')->value;

    $prevNode = $this->commonRepository->getPreviousEntity(
      $edooVillage->id(),
      $edooVillage->bundle()
    );
    $prevLink = $this->linkHelper->generateUrlFromRoute(
      'entity.node.canonical',
      ['node' => $prevNode],
    );

    $nextNode = $this->commonRepository->getNextEntity(
      $edooVillage->id(),
      $edooVillage->bundle()
    );
    $nextLink = $this->linkHelper->generateUrlFromRoute(
      'entity.node.canonical',
      ['node' => $nextNode],
    );

    $gallery = $this->galleryRepository->loadByParentId($edooVillage->id());
    $galleryLink = '';
    if ($gallery !== NULL) {
      $galleryLink = $this->linkHelper->generateUrlFromRoute(
        'entity.node.canonical',
        ['node' => $gallery->id()],
      );
    }

    $storyNode = $this->commonRepository->getStory($edooVillage->id());
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
            'parent_id' => $edooVillage->id(),
          ]
        ]
      );
    }

    $cacheTags = [
      'edoovillage:' . $edooVillage->id(),
    ];

    return [
      '#theme' => 'edoovillage_actions_block_block',
      '#edit_link' => $editLink,
      '#photo_album_link' => $galleryLink,
      '#write_story_link' => $writeStoryLink,
      '#story_link' => $storyLink,
      '#revisions_link' => $revisionsLink,
      '#clone_link' => $cloneLink,
      '#next_link' => $nextLink,
      '#previous_link' => $prevLink,
      '#semaphore' => $semaphore,
      '#status' => $status,
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'contexts' => [
          'url.path',
          'user.permissions',
        ],
        'tags' => $cacheTags,
      ],
    ];
  }

}
