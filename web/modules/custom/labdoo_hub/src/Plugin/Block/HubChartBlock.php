<?php

namespace Drupal\labdoo_hub\Plugin\Block;

use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\labdoo_hub\Service\Repository\HubRepositoryInterface;
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
 *
 * @phpstan-consistent-constructor
 */
class HubChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The Hub repository.
   *
   * @var \Drupal\labdoo_hub\Service\Repository\HubRepositoryInterface
   */
  protected HubRepositoryInterface $hubRepository;

  /**
   * HubChartBlock constructor.
   *
   * @param array $configuration
   *   The configuration array.
   * @param mixed $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\labdoo_hub\Service\Repository\HubRepositoryInterface $hubRepository
   *   The Hub repository.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request_stack service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentAccount
   *   The current_user service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    HubRepositoryInterface $hubRepository,
    protected RequestStack $requestStack,
    protected AccountProxyInterface $currentAccount,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->hubRepository = $hubRepository;
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
    $plugin_definition,
  ) {
    /** @var \Drupal\labdoo_hub\Service\Repository\HubRepositoryInterface $hubRepository */
    $hubRepository = $container->get('labdoo_hub.repository');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $hubRepository,
      $container->get('request_stack'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $userId = $this->requestStack->getCurrentRequest()->query->get('u');
    $mine = $this->requestStack->getCurrentRequest()->query->get('mine');
    $filterUserId = NULL;

    if (!empty($userId) && is_numeric($userId)) {
      $filterUserId = (int) $userId;
    }
    elseif (!empty($mine) && (int) $mine === 1) {
      $filterUserId = (int) $this->currentAccount->id();
    }

    $stats = $this->hubRepository->getStats($filterUserId);

    return [
      '#theme' => 'hub_chart_block_block',
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
          'session',
        ],
        'tags' => [
          'hub_chart',
        ],
      ],
    ];
  }

}
