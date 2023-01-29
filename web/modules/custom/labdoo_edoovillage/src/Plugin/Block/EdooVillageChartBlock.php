<?php

namespace Drupal\labdoo_edoovillage\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\labdoo_common\Service\Repository\ViewRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'EdooVillage chart' block.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Block(
 *   id = "edoovillage_chart_block_block",
 *   admin_label = @Translation("EdooVillage chart"),
 *   category = @Translation("EdooVillage"),
 * )
 */
class EdooVillageChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
      'edoovillages',
      'page_1'
    );

    foreach ($results as $row) {
      $edooVillage = $row->_entity;
      if ($edooVillage === NULL) {
        continue;
      }

      $needed += $edooVillage->get('field_number_of_laptops_needed')->value;
      $delivered += $edooVillage->get('field_dootronics_delivered')->value;
      $inTransit += $edooVillage->get('field_dootronics_in_transit')->value;
      $remaining += $edooVillage->get('field_dootronics_remaining')->value;
    }

    return [
      '#theme' => 'edoovillage_chart_block_block',
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
          'edoovillages_chart'
        ],
      ],
    ];
  }

}
