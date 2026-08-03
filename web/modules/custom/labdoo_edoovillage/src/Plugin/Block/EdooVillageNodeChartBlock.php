<?php

namespace Drupal\labdoo_edoovillage\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\labdoo_common\Service\Helper\LinkHelper;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'EdooVillage Node Chart' block.
 *
 * @Block(
 *   id = "edoovillage_node_chart_block_block",
 *   admin_label = @Translation("EdooVillage node chart"),
 *   category = @Translation("EdooVillage"),
 * )
 */
class EdooVillageNodeChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * EdooVillageNodeChartBlock constructor.
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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LinkHelper $linkHelper,
    CommonRepository $commonRepository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->linkHelper = $linkHelper;
    $this->commonRepository = $commonRepository;
  }

  /**
   * {@inheritdoc}
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

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $linkHelper,
      $commonRepository
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $edooVillage = $this->linkHelper->getActiveNode();

    // Fallback for arg_0 (views).
    if (!($edooVillage instanceof \Drupal\node\NodeInterface) || $edooVillage->bundle() !== 'edoovillage') {
      $entityId = $this->linkHelper->getActiveNode('arg_0');
      if ($entityId) {
        $edooVillage = $this->linkHelper->loadEntity($entityId);
      }
    }

    if ($edooVillage instanceof \Drupal\node\NodeInterface && $edooVillage->bundle() === 'edoovillage') {
      return AccessResult::allowed()->addCacheContexts(['url.path']);
    }

    return AccessResult::forbidden()->addCacheContexts(['url.path']);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $edooVillage = $this->linkHelper->getActiveNode();

    // Fallback for arg_0 (views) or specific routes.
    if (!($edooVillage instanceof \Drupal\node\NodeInterface) || $edooVillage->bundle() !== 'edoovillage') {
      $entityId = $this->linkHelper->getActiveNode('arg_0');
      if ($entityId) {
        $edooVillage = $this->linkHelper->loadEntity($entityId);
      }
    }

    if (!($edooVillage instanceof \Drupal\node\NodeInterface) || $edooVillage->bundle() !== 'edoovillage') {
      return [
        '#markup' => '',
      ];
    }

    $nid = (int) $edooVillage->id();

    // 1. Dootronics needed
    $needed = labdoo_get_demand($edooVillage);

    // 3. Dootronics in transit: T1, S3
    $in_transit = $this->commonRepository->getDootronicsCountByStatus(['T1', 'S3'], $nid);

    // 4. Dootronics delivered (working): S4, S7, S8
    $delivered_working = $this->commonRepository->getDootronicsCountByStatus(['S4', 'S7', 'S8'], $nid);

    // 5. Dootronics delivered (broken): S5, S9
    $delivered_broken = $this->commonRepository->getDootronicsCountByStatus(['S5', 'S9'], $nid);

    // 6. Dootronics recycled: S6
    $recycled = $this->commonRepository->getDootronicsCountByStatus('S6', $nid);

    // 2. Dootronics tagged: in_transit + delivered_working + delivered_broken + recycled
    $tagged = $in_transit + $delivered_working + $delivered_broken + $recycled;

    $cacheTags = [
      'edoovillage:' . $nid,
    ];

    return [
      '#theme' => 'edoovillage_node_chart_block_block',
      '#needed' => $needed,
      '#tagged' => $tagged,
      '#in_transit' => $in_transit,
      '#delivered_working' => $delivered_working,
      '#delivered_broken' => $delivered_broken,
      '#recycled' => $recycled,
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'contexts' => [
          'url.path',
          'user.permissions',
        ],
        'tags' => $cacheTags,
      ],
    ];
  }

}
