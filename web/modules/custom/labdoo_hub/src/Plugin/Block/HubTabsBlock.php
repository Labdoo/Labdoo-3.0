<?php

namespace Drupal\labdoo_hub\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\labdoo_common\Service\Helper\LinkHelper;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Hub tabs' block.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Block(
 *   id = "hub_tabs_block_block",
 *   admin_label = @Translation("Hub tabs"),
 *   category = @Translation("Hub"),
 * )
 */
class HubTabsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * HubTabsBlock constructor.
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
    protected LinkHelper $linkHelper,
    protected CommonRepository $commonRepository,
    protected AccountProxyInterface $currentUser,
    protected RouteMatchInterface $routeMatch,
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
    /** @var \Drupal\Core\Routing\RouteMatchInterface $routeMatch */
    $routeMatch = $container->get('current_route_match');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $linkHelper,
      $commonRepository,
      $currentUser,
      $routeMatch
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $entity = $this->routeMatch->getParameter('node');

    // Fallback for arg_0 (views).
    if (!($entity instanceof NodeInterface) || $entity->bundle() !== 'hub') {
      $entityId = $this->routeMatch->getParameter('arg_0');
      if ($entityId) {
        $entity = $this->linkHelper->loadEntity($entityId);
      }
    }

    if (!($entity instanceof NodeInterface) || $entity->bundle() !== 'hub') {
      return [];
    }

    $currentRoute = $this->routeMatch->getRouteName();
    $activeTab = '';

    $dataLink = $this->linkHelper->generateUrlFromRoute(
      'entity.node.canonical',
      ['node' => $entity->id()],
    );
    if ($currentRoute === 'entity.node.canonical') {
      $activeTab = 'data';
    }

    $dootronicsLink = $this->linkHelper->generateUrlFromRoute(
      'view.dootronics_dashboard.page_3',
      ['arg_0' => $entity->id()],
    );
    if ($currentRoute === 'view.dootronics_dashboard.page_3') {
      $activeTab = 'dootronics';
    }

    $dootripsLink = $this->linkHelper->generateUrlFromRoute(
      'view.dootrips_dashboard.page_3',
      ['arg_0' => $entity->id()],
    );
    if ($currentRoute === 'view.dootrips_dashboard.page_3') {
      $activeTab = 'dootrips';
    }

    $cacheTags = [
      'hub:' . $entity->id(),
    ];

    return [
      '#theme' => 'hub_tabs_block_block',
      '#data_link' => $dataLink,
      '#dootronics_link' => $dootronicsLink,
      '#dootrips_link' => $dootripsLink,
      '#metrics_link' => '',
      '#active_tab' => $activeTab,
      '#cache' => [
        'contexts' => [
          'url.path',
          'user',
        ],
        'tags' => $cacheTags,
      ],
    ];
  }

}
