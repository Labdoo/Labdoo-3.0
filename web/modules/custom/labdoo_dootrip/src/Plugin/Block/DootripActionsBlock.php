<?php

namespace Drupal\labdoo_dootrip\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\labdoo_common\Service\Helper\LinkHelper;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'Dootrip actions' block.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Block(
 *   id = "dootrip_actions_block_block",
 *   admin_label = @Translation("Dootrip actions"),
 *   category = @Translation("Dootrip"),
 * )
 */
class DootripActionsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * DootripActionsBlock constructor.
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
    $dootrip = $this->linkHelper->getActiveNode();
    if (!$dootrip) {
      return [
        '#markup' => '',
      ];
    }

    $editLink = $this->linkHelper->generateEditLink($dootrip);
    $revisionsLink = $this->linkHelper->generateRevisionLink($dootrip);
    $cloneLink = $this->linkHelper->generateCloneLink($dootrip);
    $status = $dootrip->get('field_status_dootrip')->value;

    $statusLabel = '';
    $options = $dootrip
      ->get('field_status_dootrip')
      ->getFieldDefinition()
      ->getSetting('allowed_values');
    if (isset($options[$status])) {
      $statusLabel = $options[$status];
    }

    $prevNode = $this->commonRepository->getPreviousEntity(
      $dootrip->id(),
      $dootrip->bundle()
    );
    $prevLink = $this->linkHelper->generateUrlFromRoute(
      'entity.node.canonical',
      ['node' => $prevNode],
    );

    $nextNode = $this->commonRepository->getNextEntity(
      $dootrip->id(),
      $dootrip->bundle()
    );
    $nextLink = $this->linkHelper->generateUrlFromRoute(
      'entity.node.canonical',
      ['node' => $nextNode],
    );

    $cacheTags = [
      sprintf(
        'dootrip:%d:%d',
        $dootrip->id(),
        $this->currentUser->id()
      ),
    ];

    return [
      '#theme' => 'dootrip_actions_block_block',
      '#edit_link' => $editLink,
      '#revisions_link' => $revisionsLink,
      '#clone_link' => $cloneLink,
      '#next_link' => $nextLink,
      '#previous_link' => $prevLink,
      '#status' => $statusLabel,
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
