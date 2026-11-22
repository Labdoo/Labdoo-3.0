<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Fresh SQL reads without deleting or populating persistent entity caches.
 */
final class ReadOnlyEntityLoader {
  /**
   * The scoped entity storages with persistent caches disabled.
   */
  private static array $storages = [];

  /**
   * Creates scoped entity storage with persistent and static caches disabled.
   */
  public static function storage(string $type): EntityStorageInterface {
    if (!isset(self::$storages[$type])) {
      $definition = clone \Drupal::entityTypeManager()->getDefinition($type);
      $definition->set('persistent_cache', FALSE);
      $definition->set('static_cache', FALSE);
      $class = $definition->getStorageClass();
      $storage = $class::createInstance(\Drupal::getContainer(), $definition);
      // SQL storage replaces the constructor definition with the active global
      // definition. Apply the private clone after construction, never globally.
      if (!$storage instanceof EntityStorageInterface || !method_exists($storage, 'setEntityType')) {
        throw new \RuntimeException('A scoped SQL entity storage is required.');
      }
      $storage->setEntityType($definition);
      if ($storage->getEntityType()->isPersistentlyCacheable() || $storage->getEntityType()->isStaticallyCacheable()) {
        throw new \RuntimeException('Read-only storage cache flags were not applied.');
      }
      self::$storages[$type] = $storage;
    }
    return self::$storages[$type];
  }

  /**
   * Loads the exact referenced paragraph revision and verifies its identity.
   */
  public static function paragraph($item): ParagraphInterface {
    $values = $item->getValue();
    $storage = self::storage('paragraph');
    if (!$storage instanceof RevisionableStorageInterface) {
      throw new \UnexpectedValueException('Paragraph storage must support revisions.');
    }
    $entity = !empty($values['target_revision_id']) ? $storage->loadRevision($values['target_revision_id']) : NULL;
    if (!$entity instanceof ParagraphInterface || (int) $entity->id() !== (int) ($values['target_id'] ?? 0)) {
      throw new \RuntimeException('Dangling or mismatched paragraph revision.');
    }
    return $entity;
  }

  /**
   * Loads a node through the scoped read-only storage.
   */
  public static function node(int $id): ?NodeInterface {
    $entity = self::storage('node')->load($id);
    if ($entity !== NULL && !$entity instanceof NodeInterface) {
      throw new \UnexpectedValueException('Node storage returned an incompatible entity.');
    }
    return $entity;
  }

  /**
   * Loads nodes in one scoped query while preserving their identity keys.
   *
   * @return array<int|string, \Drupal\node\NodeInterface>
   *   The requested nodes that still exist.
   */
  public static function nodes(array $ids): array {
    $nodes = [];
    foreach (self::storage('node')->loadMultiple($ids) as $id => $entity) {
      if (!$entity instanceof NodeInterface) {
        throw new \UnexpectedValueException('Node storage returned an incompatible entity.');
      }
      $nodes[$id] = $entity;
    }
    return $nodes;
  }

  /**
   * Loads an account through the scoped read-only storage.
   */
  public static function user(int $id): ?UserInterface {
    $entity = self::storage('user')->load($id);
    if ($entity !== NULL && !$entity instanceof UserInterface) {
      throw new \UnexpectedValueException('User storage returned an incompatible entity.');
    }
    return $entity;
  }

  /**
   * Loads a file through the scoped read-only storage.
   */
  public static function file(int $id): ?FileInterface {
    $entity = self::storage('file')->load($id);
    if ($entity !== NULL && !$entity instanceof FileInterface) {
      throw new \UnexpectedValueException('File storage returned an incompatible entity.');
    }
    return $entity;
  }

  /**
   * Loads media through the scoped read-only storage.
   */
  public static function media(int $id): ?MediaInterface {
    $entity = self::storage('media')->load($id);
    if ($entity !== NULL && !$entity instanceof MediaInterface) {
      throw new \UnexpectedValueException('Media storage returned an incompatible entity.');
    }
    return $entity;
  }

}
