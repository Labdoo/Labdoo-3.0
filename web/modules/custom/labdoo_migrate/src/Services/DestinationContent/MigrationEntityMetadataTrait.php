<?php

declare(strict_types=1);

namespace Drupal\labdoo_migrate\Services\DestinationContent;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\labdoo_common\Utility\EntityMetadata;

/**
 * Writes transient migration metadata through Core's non-field value API.
 */
trait MigrationEntityMetadataTrait {

  /**
   * Sets metadata without treating it as a field item list.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The destination content entity.
   * @param string $name
   *   The transient property name.
   * @param mixed $value
   *   The transient value, including NULL when a source ID is unavailable.
   *
   * @throws \InvalidArgumentException
   *   If the entity does not implement Core's non-field value API.
   */
  protected function setMigrationMetadata(ContentEntityInterface $entity, string $name, mixed $value): void {
    EntityMetadata::set($entity, $name, $value);
  }

}
