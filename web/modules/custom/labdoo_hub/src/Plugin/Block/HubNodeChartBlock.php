<?php

namespace Drupal\labdoo_hub\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the progress metrics of the current hub.
 *
 * @Block(
 *   id = "hub_node_chart_block",
 *   admin_label = @Translation("Hub progress chart"),
 *   category = @Translation("Labdoo")
 * )
 */
class HubNodeChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a hub progress chart.
   *
   * @param array $configuration
   *   The block configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route.
   * @param \Drupal\labdoo_common\Service\Repository\CommonRepository $repository
   *   The metrics repository.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected RouteMatchInterface $routeMatch, protected CommonRepository $repository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('current_route_match'), $container->get('labdoo_common.repository.common'));
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $hub = $this->routeMatch->getParameter('node');
    if (!$hub instanceof NodeInterface || $hub->bundle() !== 'hub') {
      return AccessResult::forbidden()->addCacheContexts(['route']);
    }
    return $hub->access('view', $account, TRUE)->addCacheContexts(['route']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $hub = $this->routeMatch->getParameter('node');
    if (!$hub instanceof NodeInterface || $hub->bundle() !== 'hub') {
      return [];
    }
    $id = (int) $hub->id();
    return [
      '#theme' => 'hub_node_chart',
      '#needed' => $this->repository->getDootronicsNeededByHub($id),
      '#tagged' => $this->repository->getDootronicsCountByStatus(NULL, NULL, $id),
      '#in_transit' => $this->repository->getDootronicsCountByStatus(
        ['T1', 'S3'], NULL, $id
      ),
      '#delivered_working' => $this->repository->getDootronicsCountByStatus(
        ['S4', 'S7', 'S8'], NULL, $id
      ),
      '#delivered_broken' => $this->repository->getDootronicsCountByStatus(
        ['S5', 'S9'], NULL, $id
      ),
      '#recycled' => $this->repository->getDootronicsCountByStatus('S6', NULL, $id),
      '#cache' => [
        'tags' => array_merge($hub->getCacheTags(), ['node_list']),
        'contexts' => ['route', 'user.permissions'],
      ],
    ];
  }

}
