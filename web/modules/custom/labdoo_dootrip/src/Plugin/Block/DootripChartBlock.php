<?php

namespace Drupal\labdoo_dootrip\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_common\Service\Repository\ViewRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'Dootrip chart' block.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Block(
 *   id = "dootrip_chart_block_block",
 *   admin_label = @Translation("Dootrip chart"),
 *   category = @Translation("Dootrip"),
 * )
 */
class DootripChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The chart block generator.
   *
   * @var \Drupal\labdoo_common\Service\Repository\ViewRepository
   */
  protected ViewRepository $viewRepository;

  /**
   * The common repository.
   *
   * @var \Drupal\labdoo_common\Service\Repository\CommonRepository
   */
  protected CommonRepository $commonRepository;

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
    ViewRepository $viewRepository,
    CommonRepository $commonRepository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->viewRepository = $viewRepository;
    $this->commonRepository = $commonRepository;
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
    /** @var \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository */
    $commonRepository = $container->get('labdoo_common.repository.common');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $viewRepository,
      $commonRepository
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $capacity = 0;
    $inTransit = 0;
    $transported = 0;
    $results = $this->viewRepository->getResults(
      'dootrips_dashboard',
      'page_1'
    );

    foreach ($results as $row) {
      $dootrip = $row->_entity;
      if ($dootrip === NULL) {
        continue;
      }

      $capacity += $dootrip->get('field_dootrip_capacity')->value;
      $inTransit += $dootrip->get('field_dootronics_in_transit')->value;
      $transported += $dootrip->get('field_dootronics_delivered')->value;
    }

    $total = $this->commonRepository->getDootripsCount();

    return [
      '#theme' => 'dootrip_chart_block_block',
      '#capacity' => $capacity,
      '#in_transit' => $inTransit,
      '#transported' => $transported,
      '#total' => $total,
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'contexts' => [
          'url.path',
          'session',
        ],
        'tags' => [
          'dootrip_chart'
        ],
      ],
    ];
  }

}
