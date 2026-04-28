<?php

namespace Drupal\labdoo_dootrip\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface;
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
   * The Dootrip repository.
   *
   * @var \Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface
   */
  protected DootripRepositoryInterface $dootripRepository;

  /**
   * The common repository.
   *
   * @var \Drupal\labdoo_common\Service\Repository\CommonRepository
   */
  protected CommonRepository $commonRepository;

  /**
   * DootripChartBlock constructor.
   *
   * @param array $configuration
   *   The configuration array.
   * @param mixed $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface $dootripRepository
   *   The Dootrip repository.
   * @param \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository
   *   The common repository.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DootripRepositoryInterface $dootripRepository,
    CommonRepository $commonRepository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->dootripRepository = $dootripRepository;
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
    /** @var \Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface $dootripRepository */
    $dootripRepository = $container->get('labdoo_dootrip.repository');
    /** @var \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository */
    $commonRepository = $container->get('labdoo_common.repository.common');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $dootripRepository,
      $commonRepository
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
      $filterUserId = (int) \Drupal::currentUser()->id();
    }

    $stats = $this->dootripRepository->getStats($filterUserId);
    $total = $this->commonRepository->getDootripsCount($filterUserId);

    return [
      '#theme' => 'dootrip_chart_block_block',
      '#capacity' => $stats['capacity'],
      '#in_transit' => $stats['in_transit'],
      '#transported' => $stats['transported'],
      '#total' => $total,
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'contexts' => [
          'url.path',
          'url.query_args:u',
          'url.query_args:mine',
          'session',
        ],
        'tags' => [
          'dootrip_chart'
        ],
      ],
    ];
  }

}
