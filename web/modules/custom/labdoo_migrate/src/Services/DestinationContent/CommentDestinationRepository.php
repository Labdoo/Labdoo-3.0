<?php

namespace Drupal\labdoo_migrate\Services\DestinationContent;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface;

use Drupal\Core\Entity\RevisionableInterface;

/**
 * The comment destination repository.
 */
class CommentDestinationRepository extends DestinationRepository {

  /**
   * {@inheritdoc}
   */
  protected function saveEntity(EntityInterface $entity): bool {
    if (!$this->indexingEnabled) {
      $entity->search_api_skip_tracking = TRUE;
    }

    try {
      // Comments do not support revisions in Drupal 10 by default.
      if ($entity instanceof RevisionableInterface && $entity->getEntityType()->isRevisionable()) {
        $entity->setNewRevision(FALSE);
      }
      return $entity->save();
    }
    catch (\Exception | \Throwable $e) {
      if ($entity->id()) {
        $this->logger->warning(sprintf(
          'Save failed for comment %d. Attempting to purge orphaned field data and retry. Error: %s',
          $entity->id(),
          $e->getMessage()
        ));

        $this->purgeOrphanedCommentFieldData((int) $entity->id());

        try {
          return $entity->save();
        }
        catch (\Exception | \Throwable $e2) {
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
   * Prepares an entity for creation.
   *
   * @param int $entityId
   *   The entity ID.
   * @param string $contentType
   *   The content type.
   * @param string $langCode
   *   The language code.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity object.
   */
  protected function prepareEntity(
    int $entityId,
    string $contentType,
    string $langCode
  ): EntityInterface {
    $entity = $this->entityTypeManager
      ->getStorage('comment')
      ->load($entityId);
    if ($entity !== NULL) {
      return $entity;
    }

    // Always purge orphaned field data before creating a new comment with a fixed ID.
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
      if (!($storage instanceof SqlContentEntityStorage)) {
        return;
      }
      $tableMapping = $storage->getTableMapping();
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
   * @param array $entities
   *   The entities array.
   *
   * @return array
   *   Returns the processed array.
   */
  protected function addSourceIdsAsKeys(array $entities): array {
    $processedEntities = [];

    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    foreach ($entities as $entity) {
      // In comments, we want the cid to be the same as source cid.
      $sourceId = $entity->id();
      $processedEntities[$sourceId] = $entity;
    }

    return $processedEntities;
  }

}
