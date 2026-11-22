<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Utility;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Writes runtime entity values through Core's documented magic value API.
 */
final class EntityMetadata {

  /**
   * Preserves scalar runtime flags without modelling them as field item lists.
   */
  public static function set(ContentEntityInterface $entity, string $name, mixed $value): void {
    if (!$entity instanceof ContentEntityBase) {
      throw new \InvalidArgumentException('Runtime metadata requires the Core content entity value API.');
    }
    $entity->__set($name, $value);
  }

}
