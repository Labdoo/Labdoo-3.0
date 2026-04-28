<?php

namespace Drupal\labdoo_dootronics\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'Dootronics chart block.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Block(
 *   id = "dootronics_chart_block_block",
 *   admin_label = @Translation("Dootronic chart"),
 *   category = @Translation("Dootronic"),
 * )
 */
class DootronicsChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The Dootronic repository.
   *
   * @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface
   */
  protected DootronicRepositoryInterface $dootronicRepository;

  /**
   * DootronicsChartBlock constructor.
   *
   * @param array $configuration
   *   The configuration array.
   * @param mixed $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository
   *   The Dootronic repository.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DootronicRepositoryInterface $dootronicRepository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->dootronicRepository = $dootronicRepository;
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
    /** @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository */
    $dootronicRepository = $container->get('labdoo_dootronics.repository');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $dootronicRepository
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $statusInfo = [
      'S0' => [
        'name' => 'tagged',
        'count' => 0,
        'color' => '#a5682a',
      ],
      'S1' => [
        'name' => 'donated',
        'count' => 0,
        'color' => '#dc3912',
      ],
      'S2' => [
        'name' => 'sanitized',
        'count' => 0,
        'color' => '#109618',
      ],
      'S3' => [
        'name' => 'assigned',
        'count' => 0,
        'color' => '#005e7a',
      ],
      'T1' => [
        'name' => 'in transit',
        'count' => 0,
        'color' => '#5a5a5a',
      ],
      'S4' => [
        'name' => 'deployed',
        'count' => 0,
        'color' => '#ff8000',
      ],
      'S5' => [
        'name' => 'needs recycled',
        'count' => 0,
        'color' => '#e67300',
      ],
      'S6' => [
        'name' => 'recycled',
        'count' => 0,
        'color' => '#808080',
      ],
    ];

    $userId = \Drupal::request()->query->get('u');
    $mine = \Drupal::request()->query->get('mine');
    $filterUserId = NULL;

    if (!empty($userId) && is_numeric($userId)) {
      $filterUserId = (int) $userId;
    }
    elseif (!empty($mine) && (int) $mine === 1) {
      $filterUserId = (int) \Drupal::currentUser()->id();
    }

    $stats = $this->dootronicRepository->getStats($filterUserId);

    foreach ($stats as $status => $count) {
      if (isset($statusInfo[$status])) {
        $statusInfo[$status]['count'] = (int) $count;
      }
    }

    return [
      '#theme' => 'dootronics_chart_block_block',
      '#status_info' => $statusInfo,
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'contexts' => [
          'url.path',
          'url.query_args:u',
          'url.query_args:mine',
          'session',
        ],
        'tags' => [
          'dootronics_chart'
        ],
      ],
    ];
  }

}
