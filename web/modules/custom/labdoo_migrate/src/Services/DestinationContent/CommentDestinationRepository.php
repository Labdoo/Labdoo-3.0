<?php

namespace Drupal\labdoo_migrate\Services\DestinationContent;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;

/**
 * The comment destination repository.
 */
class CommentDestinationRepository extends DestinationRepository {

  /**
   * {@inheritdoc}
   */
  protected function saveEntity(ContentEntityInterface $entity): bool {
    if (!$this->indexingEnabled) {
      $this->setMigrationMetadata($entity, 'search_api_skip_tracking', TRUE);
    }

    $this->setMigrationMetadata($entity, 'labdoo_skip_geocoding', TRUE);

    try {
      // Comments do not support revisions in Drupal 10 by default.
      if ($entity->getEntityType()->isRevisionable()) {
        $entity->setNewRevision(FALSE);
      }
      return (bool) $entity->save();
    }
    catch (\Throwable $e) {
      if ($entity->id()) {
        $this->logger->warning(sprintf(
          'Save failed for comment %d. Attempting to purge orphaned field data and retry. Error: %s',
          $entity->id(),
          $e->getMessage()
        ));

        $this->purgeOrphanedCommentFieldData((int) $entity->id());

        try {
          return (bool) $entity->save();
        }
        catch (\Throwable $e2) {
          $this->logger->error(sprintf(
            'Retry save failed for comment %d: %s',
            $entity->id(),
            $e2->getMessage()
          ));
        }
      }
      else {
        $this->logger->error(sprintf(
          'Error updating comment: %s',
          $e->getMessage()
        ));
      }
    }

    return FALSE;
  }

  /**
   * Creates a comment entity with a fixed ID.
   *
   * @param array $values
   *   The entity values.
   * @param int $entityId
   *   The entity ID.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The created entity.
   */
  public function create(array $values, int $entityId): ContentEntityInterface {
    // Always purge orphaned field data before creating a new comment with a
    // fixed ID.
    $this->purgeOrphanedCommentFieldData($entityId);

    $values['cid'] = $entityId;
    return $this->entityTypeManager
      ->getStorage('comment')
      ->create($values);
  }

  /**
   * Prepares an entity for creation.
   *
   * @param int $entityId
   *   The entity ID.
   * @param string $contentType
   *   The content type.
   * @param string $langCode
   *   The language code.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity object.
   */
  protected function prepareEntity(
    int $entityId,
    string $contentType,
    string $langCode,
  ): ContentEntityInterface {
    $entity = $this->entityTypeManager
      ->getStorage('comment')
      ->load($entityId);
    if ($entity !== NULL) {
      return $entity;
    }

    // Always purge orphaned field data before creating a new comment with a
    // fixed ID.
    $this->purgeOrphanedCommentFieldData($entityId);

    return $this->entityTypeManager
      ->getStorage('comment')
      ->create([
        'comment_type' => $contentType,
        'langcode' => $langCode,
        'cid' => $entityId,
        'entity_type' => 'node',
        'field_name' => 'comment',
      ]);
  }

  /**
   * Deletes orphaned field data for a comment ID from all dedicated tables.
   *
   * @param int $entityId
   *   The entity ID whose orphaned field data should be removed.
   */
  private function purgeOrphanedCommentFieldData(int $entityId): void {
    try {
      $storage = $this->entityTypeManager->getStorage('comment');
      $tableMapping = $storage->getTableMapping();
      if (!$tableMapping instanceof DefaultTableMapping) {
        return;
      }
      $fieldDefinitions = $this->entityFieldManager
        ->getFieldStorageDefinitions('comment');
      $database = $this->database;

      foreach ($fieldDefinitions as $definition) {
        if ($tableMapping->requiresDedicatedTableStorage($definition)) {
          $dataTable = $tableMapping->getDedicatedDataTableName($definition);
          if ($database->schema()->tableExists($dataTable)) {
            $database->delete($dataTable)
              ->condition('entity_id', $entityId)
              ->execute();
          }
          $revisionTable = $tableMapping->getDedicatedRevisionTableName($definition);
          if ($database->schema()->tableExists($revisionTable)) {
            $database->delete($revisionTable)
              ->condition('entity_id', $entityId)
              ->execute();
          }
        }
      }
      $this->logger->debug(sprintf(
        'Purged orphaned field data for comment %d before creation.',
        $entityId
      ));
    }
    catch (\Exception $e) {
      $this->logger->warning(sprintf(
        'Could not purge orphaned field data for comment %d: %s',
        $entityId,
        $e->getMessage()
      ));
    }
  }

  /**
   * {@inheritDoc}
   */
  public function getEntities(array $contentTypes, array $nids = []): array {
    $properties = ['comment_type' => $contentTypes];
    if ($nids) {
      $properties['cid'] = $nids;
    }
    $entities = $this->entityTypeManager
      ->getStorage('comment')
      ->loadByProperties($properties);

    return $this->addSourceIdsAsKeys($entities);
  }

  /**
   * Adds the source IDs as the array keys.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $entities
   *   The entities array.
   *
   * @return array<int, \Drupal\Core\Entity\ContentEntityInterface>
   *   Returns the processed array.
   */
  protected function addSourceIdsAsKeys(array $entities): array {
    $processedEntities = [];

    foreach ($entities as $entity) {
      // In comments, we want the cid to be the same as source cid.
      $sourceId = $entity->id();
      $processedEntities[$sourceId] = $entity;
    }

    return $processedEntities;
  }

}
