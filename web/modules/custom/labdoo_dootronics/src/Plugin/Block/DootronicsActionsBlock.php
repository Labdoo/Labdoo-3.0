<?php

namespace Drupal\labdoo_dootronics\Plugin\Block;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\labdoo_common\Service\Helper\LinkHelper;

/**
 * Provides an 'Dootronics actions' block.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Block(
 *   id = "dootronics_actions_block_block",
 *   admin_label = @Translation("Dootronics actions"),
 *   category = @Translation("Dootronics"),
 * )
 */
class DootronicsActionsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * The dootronics repository.
   *
   * @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface
   */
  protected DootronicRepositoryInterface $dootronicRepository;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * DootronicsActionsBlock constructor.
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
   * @param \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository
   *   The dootronic repository.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LinkHelper $linkHelper,
    CommonRepository $commonRepository,
    DootronicRepositoryInterface $dootronicRepository,
    AccountProxyInterface $currentUser
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->linkHelper = $linkHelper;
    $this->commonRepository = $commonRepository;
    $this->dootronicRepository = $dootronicRepository;
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
    /** @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository */
    $dootronicRepository = $container->get('labdoo_dootronics.repository');
    /** @var \Drupal\Core\Session\AccountProxyInterface $currentUser */
    $currentUser = $container->get('current_user');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $linkHelper,
      $commonRepository,
      $dootronicRepository,
      $currentUser
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $dootronic = $this->linkHelper->getActiveNode();
    if (!$dootronic instanceof EntityInterface) {
      return [
        '#markup' => '',
      ];
    }

    $editLink = $this->linkHelper->generateEditLink($dootronic);
    $revisionsLink = $this->linkHelper->generateRevisionLink($dootronic);
    $cloneLink = $this->linkHelper->generateCloneLink($dootronic);
    $printLabelsLink = $this->linkHelper->generateUrlFromRoute(
      'labdoo_dootronics.print_labels',
      ['startingDootronicId' => $dootronic->id()],
      ['attributes' => ['target' => '_blank']]
    );

    if ($dootronic->bundle() === 'dootronic') {
      $prevNodeId = $this->dootronicRepository->getPreviousDootronicByTitle($dootronic->label());
    }
    else {
      $prevNodeId = $this->commonRepository->getPreviousEntity(
        $dootronic->id(),
        $dootronic->bundle()
      );
    }
    $prevLink = '';
    if ($prevNodeId > 0 && $prevNodeId != $dootronic->id()) {
      $prevLink = $this->linkHelper->generateUrlFromRoute(
        'entity.node.canonical',
        ['node' => $prevNodeId],
      );
    }

    if ($dootronic->bundle() === 'dootronic') {
      $nextNodeId = $this->dootronicRepository->getNextDootronicByTitle($dootronic->label());
    }
    else {
      $nextNodeId = $this->commonRepository->getNextEntity(
        $dootronic->id(),
        $dootronic->bundle()
      );
    }
    $nextLink = '';
    if ($nextNodeId > 0 && $nextNodeId != $dootronic->id()) {
      $nextLink = $this->linkHelper->generateUrlFromRoute(
        'entity.node.canonical',
        ['node' => $nextNodeId],
      );
    }

    $followLink = '';
    $unfollowLink = '';
    if ($this->dootronicRepository->isCurrentUserFollowingDootronic($dootronic) === FALSE) {
      $followLink = $this->linkHelper->generateUrlFromRoute(
        'labdoo_dootronics.follow_dootronic',
        ['dootronicId' => $dootronic->id()],
      );
    }
    else {
      $unfollowLink = $this->linkHelper->generateUrlFromRoute(
        'labdoo_dootronics.unfollow_dootronic',
        ['dootronicId' => $dootronic->id()],
      );
    }

    $pickMeUpStatus = (int) $dootronic->get('field_pick_me_up')->value;
    $pickMeUpLink = $this->linkHelper->generatePickMeUpLink(
      $dootronic,
      $pickMeUpStatus ? 0 : 1
    );

    $cacheTags = [
      sprintf(
        'dootronic:%d:%d',
        $dootronic->id(),
        $this->currentUser->id()
      ),
      sprintf(
        'dootronic:%d',
        $dootronic->id()
      ),
    ];

    return [
      '#theme' => 'dootronics_actions_block_block',
      '#edit_link' => $editLink,
      '#revisions_link' => $revisionsLink,
      '#clone_link' => $cloneLink,
      '#print_labels_link' => $printLabelsLink,
      '#follow_link' => $followLink,
      '#unfollow_link' => $unfollowLink,
      '#pick_me_up_status' => $pickMeUpStatus,
      '#pick_me_up_link' => $pickMeUpLink,
      '#next_link' => $nextLink,
      '#previous_link' => $prevLink,
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
