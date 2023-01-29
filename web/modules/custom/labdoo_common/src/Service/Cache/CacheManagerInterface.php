<?php

namespace Drupal\labdoo_common\Service\Cache;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * Interface for cache managers.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface CacheManagerInterface {

  /**
   * Retrieves a cached value.
   *
   * @param string $cid
   *   The cache ID.
   *
   * @return mixed
   *   The cached value.
   *
   * @throws \Exception
   */
  public function get(string $cid);

  /**
   * Caches a value.
   *
   * @param string $cid
   *   The cache ID.
   * @param mixed $value
   *   The value.
   * @param mixed $expire
   *   The expiration time.
   */
  public function set(string $cid, $value, $expire): void;

  /**
   * Invalidates cache tags.
   *
   * @param array $tags
   *   The cache tags.
   */
  public function invalidateTags(array $tags): void;

  /**
   * Retrieves a cacheable metadata object.
   *
   * Note: this method is not testable because
   * CacheableMetadata::addCacheContexts() calls Cache::mergeContexts(),
   * which has a reference to the Drupal container:
   * \Drupal::service('cache_contexts_manager')
   * As you may know, \Drupal::service is not testable by definition
   * with unit tests, but you can create a kernel test for this method.
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
   */
  public function getCacheableMetadata(
    array $renderArray,
    array $cacheContexts = [],
    array $cacheTags = []
  ): CacheableMetadata;

}
