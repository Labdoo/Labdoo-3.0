<?php

namespace Drupal\labdoo_hub\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\labdoo_common\Service\Repository\ViewRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Hub chart' block.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Block(
 *   id = "hub_chart_block_block",
 *   admin_label = @Translation("Hub chart"),
 *   category = @Translation("Hub"),
 * )
 */
class HubChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The chart block generator.
   *
   * @var \Drupal\labdoo_common\Service\Repository\ViewRepository
   */
  protected ViewRepository $viewRepository;

  /**
   * DootronicsChartBlock constructor.
   *
   * @param array $configuration
   *   The configuration array.
   * @param mixed $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\labdoo_common\Service\Repository\ViewRepository $viewRepository
   *   The View repository.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ViewRepository $viewRepository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->viewRepository = $viewRepository;
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
    /** @var \Drupal\labdoo_common\Service\Repository\ViewRepository $viewRepository */
    $viewRepository = $container->get('labdoo_common.repository.view');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $viewRepository
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $needed = 0;
    $delivered = 0;
    $inTransit = 0;
    $remaining = 0;
    $results = $this->viewRepository->getResults(
      'hubs_dashboard',
      'page_1'
    );

    foreach ($results as $row) {
      $hub = $row->_entity;
      if ($hub === NULL) {
        continue;
      }

      $needed += $hub->get('field_dootronics_needed')->value;
      $delivered += $hub->get('field_dootronics_delivered')->value;
      $inTransit += $hub->get('field_dootronics_in_transit')->value;
      $remaining += $hub->get('field_dootronics_remaining')->value;
    }

    return [
      '#theme' => 'hub_chart_block_block',
      '#needed' => $needed,
      '#delivered' => $delivered,
      '#in_transit' => $inTransit,
      '#remaining' => $remaining,
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'contexts' => [
          'url.path',
          'session',
        ],
        'tags' => [
          'hub_chart'
        ],
      ],
    ];
  }

}
