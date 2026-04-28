<?php

namespace Drupal\labdoo_edoovillage\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface;
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
   * The EdooVillage repository.
   *
   * @var \Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface
   */
  protected EdooVillageRepositoryInterface $edoovillageRepository;

  /**
   * EdooVillageChartBlock constructor.
   *
   * @param array $configuration
   *   The configuration array.
   * @param mixed $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface $edoovillageRepository
   *   The EdooVillage repository.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EdooVillageRepositoryInterface $edoovillageRepository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->edoovillageRepository = $edoovillageRepository;
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
    /** @var \Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface $edoovillageRepository */
    $edoovillageRepository = $container->get('labdoo_edoovillage.repository');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $edoovillageRepository
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $userId = \Drupal::request()->query->get('u');
    $mine = \Drupal::request()->query->get('mine');
    $filterUserId = NULL;

    if (!empty($userId) && is_numeric($userId)) {
      $filterUserId = (int) $userId;
    }
    elseif (!empty($mine) && (int) $mine === 1) {
      $filterUserId = \Drupal::currentUser()->id();
    }

    $stats = $this->edoovillageRepository->getStats($filterUserId);

    return [
      '#theme' => 'edoovillage_chart_block_block',
      '#needed' => $stats['needed'],
      '#delivered' => $stats['delivered'],
      '#in_transit' => $stats['in_transit'],
      '#remaining' => $stats['remaining'],
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'contexts' => [
          'url.path',
          'url.query_args:u',
          'url.query_args:mine',
          'user.permissions',
        ],
        'tags' => [
          'edoovillages_chart'
        ],
      ],
    ];
  }

}
