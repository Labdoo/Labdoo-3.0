<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Entity\EntityStorageInterface;

/** Fresh SQL reads without deleting or populating persistent entity caches. */
final class ReadOnlyEntityLoader {
  private static array $storages = [];

  public static function storage(string $type): EntityStorageInterface {
    if (!isset(self::$storages[$type])) {
      $definition = clone \Drupal::entityTypeManager()->getDefinition($type);
      $definition->set('persistent_cache', FALSE);
      $definition->set('static_cache', FALSE);
      $class = $definition->getStorageClass();
      $storage = $class::createInstance(\Drupal::getContainer(), $definition);
      // SQL storage replaces the constructor definition with the active global
      // definition. Apply the private clone after construction, never globally.
      if (!method_exists($storage, 'setEntityType')) { throw new \RuntimeException('A scoped SQL entity storage is required.'); }
      $storage->setEntityType($definition);
      if ($storage->getEntityType()->isPersistentlyCacheable() || $storage->getEntityType()->isStaticallyCacheable()) {
        throw new \RuntimeException('Read-only storage cache flags were not applied.');
      }
      self::$storages[$type] = $storage;
    }
    return self::$storages[$type];
  }

  public static function paragraph($item) {
    $values = $item->getValue();
    $storage = self::storage('paragraph');
    $entity = !empty($values['target_revision_id']) ? $storage->loadRevision($values['target_revision_id']) : NULL;
    if (!$entity || (int) $entity->id() !== (int) ($values['target_id'] ?? 0)) {
      throw new \RuntimeException('Dangling or mismatched paragraph revision.');
    }
    return $entity;
  }
}
