<?php

namespace Drupal\labdoo_common\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\labdoo_common\Service\Cache\CacheManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Invalidates cache tags from the queue.
 *
 * @QueueWorker(
 *   id = "labdoo_common_cache_invalidation",
 *   title = @Translation("Labdoo Common Cache Invalidation"),
 *   cron = {"time" = 60}
 * )
 */
class CacheInvalidationQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The cache manager.
   *
   * @var \Drupal\labdoo_common\Service\Cache\CacheManagerInterface
   */
  protected CacheManagerInterface $cacheManager;

  /**
   * Constructs a new CacheInvalidationQueueWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\labdoo_common\Service\Cache\CacheManagerInterface $cache_manager
   *   The cache manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    CacheManagerInterface $cache_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->cacheManager = $cache_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('labdoo_common.cache_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (isset($data['tags']) && is_array($data['tags'])) {
      $this->cacheManager->invalidateTags($data['tags']);
    }
  }

}
