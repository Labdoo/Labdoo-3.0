<?php

namespace Drupal\labdoo_common\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event that stores cache tags to invalidate.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class InvalidateCacheTagsEvent extends Event {

  /**
   * The event name.
   */
  public const EVENT_NAME = 'labdoo.event.cache_tags';

  protected array $cacheTags = [];

  /**
   * Retrieves the cache tags.
   *
   * @return array
   *   The cache tags.
   */
  public function getCacheTags(): array {
    return $this->cacheTags;
  }

  /**
   * Sets the cache tags.
   *
   * @param array $cacheTags
   *   The cache tags.
   *
   * @return void
   */
  public function setCacheTags(array $cacheTags): void {
    $this->cacheTags = $cacheTags;
  }

}
