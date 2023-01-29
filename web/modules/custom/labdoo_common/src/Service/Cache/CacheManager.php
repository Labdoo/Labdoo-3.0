<?php

namespace Drupal\labdoo_common\Service\Cache;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

/**
 * Service that handles the cache capabilities.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class CacheManager implements CacheManagerInterface {

  /**
   * The cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * The cache tags invalidator service.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * CacheManager constructor.
   */
  public function __construct(
    CacheBackendInterface $cache,
    CacheTagsInvalidatorInterface $cacheTagsInvalidator
  ) {
    $this->cache = $cache;
    $this->cacheTagsInvalidator = $cacheTagsInvalidator;
  }

  /**
   * {@inheritDoc}
   */
  public function get(string $cid) {
    $cachedValue = $this->cache->get($cid);
    if (empty($cachedValue)) {
      throw new \Exception('No cached value');
    }

    return $cachedValue->data;
  }

  /**
   * {@inheritDoc}
   */
  public function set(string $cid, $value, $expire): void {
    $this->cache->set($cid, $value, $expire);
  }

  /**
   * {@inheritDoc}
   */
  public function invalidateTags(array $tags): void {
    $this->cacheTagsInvalidator->invalidateTags($tags);
  }

  /**
   * Retrieves a cacheable metadata object.
   *
   * @param array $renderArray
   *   The render array.
   * @param array $cacheContexts
   *   The cache contexts, if any.
   * @param array $cacheTags
   *   The cache tags, if any.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cacheable metadata object.
   *
   * @codeCoverageIgnore
   *   This method cannot be tested since it relies on the use of the container.
   */
  public function getCacheableMetadata(
    array $renderArray,
    array $cacheContexts = [],
    array $cacheTags = []
  ): CacheableMetadata {
    $cacheMetadata = CacheableMetadata::createFromRenderArray($renderArray);
    if (!empty($cacheContexts)) {
      $cacheMetadata->addCacheContexts($cacheContexts);
    }
    if (!empty($cacheTags)) {
      $cacheMetadata->addCacheTags($cacheTags);
    }

    return $cacheMetadata;
  }

}
