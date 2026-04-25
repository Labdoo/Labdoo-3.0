<?php

namespace Drupal\labdoo_migrate\Services\DestinationContent;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\labdoo_migrate\Model\SpecialTypeModel;
use Drupal\labdoo_migrate\Services\SpecialFieldTypes\SpecialFieldTypeFactory;
use Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * The destination repository.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DestinationRepository implements DestinationRepositoryInterface {

  /**
   * Progress log interval.
   */
  private const PROGRESS_LOG_INTERVAL = 100;

  /**
   * Cache clear interval for long-running migrations.
   */
  private const CACHE_CLEAR_INTERVAL = 20;

  use LoggerAwareTrait;

  /**
   * The field name of the source ID.
   */
  private const SOURCE_ID_FIELD = 'field_d7_nid';

  /**
   * Contains the failing IDs.
   *
   * @var array
   */
  protected array $failingIds = [];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The translation repository.
   *
   * @var \Drupal\labdoo_migrate\Services\DestinationContent\TranslationRepositoryInterface
   */
  private TranslationRepositoryInterface $translationRepository;

  /**
   * The migration tracker.
   *
   * @var \Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface
   */
  private MigrationTrackerInterface $migrationTracker;

  /**
   * The mapping array.
   *
   * @var array
   */
  private array $mapping;

  /**
   * The dry-run mode.
   *
   * @var bool
   */
  private bool $dryRun;

  /**
   * The total source entities count.
   *
   * @var int
   */
  private int $totalSourceEntitiesCount = 0;

  /**
   * The main entities count.
   *
   * @var int
   */
  private int $mainEntitiesCount = 0;

  /**
   * The translations count.
   *
   * @var int
   */
  private int $translationsCount = 0;

  /**
   * The summary.
   *
   * @var array
   */
  private array $summary;

  /**
   * The override mode.
   *
   * @var bool
   */
  private bool $overrideMode;

  /**
   * DestinationRepository constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\labdoo_migrate\Services\DestinationContent\TranslationRepositoryInterface $translationRepository
   *   The translation repository.
   * @param \Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface $migrationTracker
   *   The migration tracker.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    TranslationRepositoryInterface $translationRepository,
    MigrationTrackerInterface $migrationTracker
  ) {

    $this->entityTypeManager = $entityTypeManager;
    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $this->translationRepository = $translationRepository;
    $this->migrationTracker = $migrationTracker;
    $this->overrideMode = FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function setOverrideMode(bool $overrideMode): void {
    $this->overrideMode = $overrideMode;
  }

  /**
   * {@inheritDoc}
   */
  public function getEntities(array $contentTypes, array $nids = []): array {

    $properties = ['type' => $contentTypes];
    if ($nids) {
      $properties['nid'] = $nids;
    }
    $entities = $this->entityTypeManager
      ->getStorage('node')
      ->loadByProperties($properties);

    return $this->addSourceIdsAsKeys($entities);
  }

  /**
   * {@inheritDoc}
   */
  public function getProcessedEntitiesSummary(): array {
    return [
      'main' => $this->mainEntitiesCount,
      'translations' => $this->translationsCount,
      'failing_ids' => $this->failingIds,
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function createEntities(
    array $sourceEntities,
    array $mapping,
    string $contentType,
    bool $dryRun = FALSE
  ): int {

    $this->mapping = $mapping;
    $this->dryRun = $dryRun;
    if ($this->totalSourceEntitiesCount === 0) {
      $this->totalSourceEntitiesCount = count($sourceEntities);
    }

    $this->disableEntityStorageCache();
    $createdEntities = 0;

    foreach ($sourceEntities as $entityId => $sourceEntity) {
      if ($this->createTranslations($sourceEntity, $contentType, $entityId)) {
        ++$createdEntities;
      }

      if ($createdEntities % self::CACHE_CLEAR_INTERVAL === 0) {
        $this->clearEntityStorageRuntimeCache();
      }
    }

    return $createdEntities;
  }

  /**
   * Creates the translations.
   *
   * @param array $sourceEntity
   *   The source entities array.
   * @param string $contentType
   *   The destination content type.
   * @param int|null $entityId
   *   The source entity ID.
   *
   * @return bool
   *   Returns the result of the process.
   *
   * @throws \Exception
   */
  protected function createTranslations(
    array $sourceEntity,
    string $contentType,
    ?int $entityId
  ): bool {

    $startedAt = microtime(TRUE);
    $result = TRUE;

    $metadata = $sourceEntity['metadata'] ?? [];
    unset($sourceEntity['metadata']);

    // Creates the main entity.
    $mainLangCode = $metadata['main_langcode'];
    $mainEntityValues = $sourceEntity[$mainLangCode] ?? NULL;

    // Fallback: get the remaining language.
    if ($mainEntityValues === NULL && count($sourceEntity) > 1) {
      throw new \Exception('Translation without main language: cannot process');
    }

    $mainEntityValues = reset($sourceEntity);
    $mainEntity = $this->prepareEntity($entityId, $contentType, $mainLangCode);
    if ($entityId !== NULL) {
      $mainEntity->original_entity_id = $entityId;
    }

    $result = $result && $this->updateEntity(
        $mainEntityValues,
        $mainEntity,
        $mainLangCode
      );

    if ($result) {
      if ($entityId !== NULL && !$this->dryRun) {
        $durationMs = (int) round((microtime(TRUE) - $startedAt) * 1000);
        $this->migrationTracker->track('node', $contentType, $entityId, (int) $mainEntity->id(), $durationMs);
      }

      ++$this->mainEntitiesCount;
      $divisor = $this->totalSourceEntitiesCount > 0 ? $this->totalSourceEntitiesCount : 1;
      if (
        $this->mainEntitiesCount === 1
        || $this->mainEntitiesCount === $this->totalSourceEntitiesCount
        || $this->mainEntitiesCount % self::PROGRESS_LOG_INTERVAL === 0
      ) {
        $message = sprintf(
          'Processed entity %d (%s) [%s] [%d/%d %s%%]',
          $mainEntity->id(),
          $mainLangCode,
          $contentType,
          $this->mainEntitiesCount,
          $this->totalSourceEntitiesCount,
          round($this->mainEntitiesCount * 100 / $divisor, 2)
        );
        $this->logger->notice($message);
      }
    }
    else {
      $this->failingIds[] = $mainEntity->id();
    }

    // Creates the translations.
    foreach ($sourceEntity as $langCode => $values) {
      if (
        empty($values)
        || $langCode === $mainLangCode
      ) {
        continue;
      }

      $translation = $this->translationRepository->getEntityTranslation(
        $mainEntity,
        $langCode
      );
      if ($entityId !== NULL) {
        $translation->original_entity_id = $metadata[$langCode] ?? NULL;
      }

      $result = $result && $this->updateEntity(
          $values,
          $translation,
          $mainLangCode
        );

      if ($result) {
        $message = sprintf(
          '--- Processed translation %d (%s)',
          $translation->id(),
          $langCode
        );
        $this->logger->debug($message);
        ++$this->translationsCount;
      }
    }

    return $result;
  }

  /**
   * {@inheritDoc}
   */
  public function updateEntities(
    array $sourceEntities,
    array $mapping,
    array $destinationEntities,
    bool $dryRun = FALSE
  ): int {

    $this->mapping = $mapping;
    $this->dryRun = $dryRun;
    if ($this->totalSourceEntitiesCount === 0) {
      $this->totalSourceEntitiesCount = count($sourceEntities);
    }

    $updatedEntities = 0;
    $this->disableEntityStorageCache();

    foreach ($sourceEntities as $entityId => $sourceEntity) {
      if ($this->updateTranslations($sourceEntity, $destinationEntities[$entityId])) {
        ++$updatedEntities;
      }

      if ($updatedEntities % self::CACHE_CLEAR_INTERVAL === 0) {
        $this->clearEntityStorageRuntimeCache();
      }
    }

    return $updatedEntities;
  }

  /**
   * Disables the entity storage cache.
   *
   * @return void
   */
  protected function disableEntityStorageCache(): void {
    $entityTypes = ['node', 'media', 'paragraph', 'taxonomy_term'];
    foreach ($entityTypes as $entityType) {
      try {
        $definition = $this->entityTypeManager
          ->getStorage($entityType)
          ->getEntityType();
        $definition->set('static_cache', FALSE);
        $definition->set('persistent_cache', FALSE);
      }
      catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
        $errorMessage = sprintf(
          'Error disabling the entity storage cache for %s: %s',
          $entityType,
          $e->getMessage()
        );
        $this->logger->error($errorMessage);
      }
    }
  }

  /**
   * Clears runtime entity storage cache to reduce memory usage.
   *
   * @return void
   */
  protected function clearEntityStorageRuntimeCache(): void {
    $entityTypes = ['node', 'media', 'paragraph', 'taxonomy_term'];
    foreach ($entityTypes as $entityType) {
      try {
        $this->entityTypeManager
          ->getStorage($entityType)
          ->resetCache();
      }
      catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
        $errorMessage = sprintf(
          'Error clearing entity storage runtime cache for %s: %s',
          $entityType,
          $e->getMessage()
        );
        $this->logger->error($errorMessage);
      }
    }

    // Force PHP garbage collection.
    gc_collect_cycles();
  }

  /**
   * Updates the translations.
   *
   * @param array $sourceEntity
   *   The source entities array.
   * @param \Drupal\Core\Entity\EntityInterface $destinationEntity
   *   The destination entity.
   *
   * @return bool
   *   Returns the result of the process.
   *
   * @throws \Exception
   */
  protected function updateTranslations(
    array $sourceEntity,
    EntityInterface $destinationEntity
  ): bool {

    $startedAt = microtime(TRUE);
    $result = TRUE;
    $defaultLangCode = $destinationEntity->language()->getId();

    foreach ($sourceEntity as $langCode => $values) {
      if ($langCode === 'metadata') {
        continue;
      }
      $currentDestinationEntity = $this->getEntityVariant(
        $langCode,
        $defaultLangCode,
        $destinationEntity
      );

      $result = $result && $this->updateEntity(
          $values,
          $currentDestinationEntity,
          $sourceEntity['metadata']['main_langcode']
        );

      if ($result) {
        $sourceId = (int) $destinationEntity->{self::SOURCE_ID_FIELD}->value;
        if ($sourceId > 0 && !$this->dryRun) {
          $durationMs = (int) round((microtime(TRUE) - $startedAt) * 1000);
          $this->migrationTracker->track('node', $destinationEntity->bundle(), $sourceId, (int) $destinationEntity->id(), $durationMs);
        }

        if (
          $this->mainEntitiesCount === 1
          || $this->mainEntitiesCount === $this->totalSourceEntitiesCount
          || $this->mainEntitiesCount % self::PROGRESS_LOG_INTERVAL === 0
        ) {
          $message = sprintf(
            'Updated entity %d (%s) [%s]',
            $currentDestinationEntity->id(),
            $langCode,
            $currentDestinationEntity->bundle()
          );
          $this->logger->notice($message);
        }
      }
    }

    return $result;
  }

  /**
   * Retrieves the entity variant for the given language code.
   *
   * @param string $langCode
   *   The language code.
   * @param string $defaultLangCode
   *   The default language code.
   * @param \Drupal\Core\Entity\EntityInterface $destinationEntity
   *   The destination entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|mixed
   *   Returns an entity.
   */
  protected function getEntityVariant(
    string $langCode,
    string $defaultLangCode,
    EntityInterface $destinationEntity
  ) {

    if ($langCode === $defaultLangCode) {
      return $destinationEntity;
    }

    return $this->translationRepository
      ->getEntityTranslation($destinationEntity, $langCode);
  }

  /**
   * Updates an entity.
   *
   * @param array $sourceEntity
   *   The source entities array.
   * @param \Drupal\Core\Entity\EntityInterface $destinationEntity
   *   The destination entity.
   *
   * @return bool
   *   Returns the result of the process.
   *
   * @throws \Exception
   */
  protected function updateEntity(
    array $sourceEntity,
    EntityInterface $destinationEntity,
    string $mainLangCode
  ): bool {

    if (empty($sourceEntity)) {
      return FALSE;
    }

    $accumulatedValues = [];

    foreach ($sourceEntity as $sourceIdentifier => $value) {
      /** @var \Drupal\labdoo_migrate\Model\MappingModel $mapping */
      if (!isset($this->mapping[$sourceIdentifier])) {
        continue;
      }
      $mapping = $this->mapping[$sourceIdentifier];
      $destination = $mapping->getDestinationField();
      $fieldName = $destination->getFieldName();

      if (!$fieldName || !$destinationEntity->hasField($fieldName)) {
        if ($fieldName && !$destinationEntity->hasField($fieldName)) {
          $this->logger->warning(sprintf('Field %s is unknown for entity %d (%s).', $fieldName, $destinationEntity->id(), $destinationEntity->bundle()));
        }
        continue;
      }

      $fieldValue = $this->getPreparedFieldValue(
        $destinationEntity,
        $fieldName,
        $value,
        $mainLangCode,
        $destination->getSpecialType()
      );

      $fieldDefinition = $destinationEntity->getFieldDefinition($fieldName);
      if ($fieldDefinition->getFieldStorageDefinition()->isMultiple()) {
        if (!isset($accumulatedValues[$fieldName])) {
          $accumulatedValues[$fieldName] = [];
        }
        if (is_array($fieldValue) && $this->checkMultiValue($fieldValue)) {
          foreach ($fieldValue as $v) {
            $accumulatedValues[$fieldName][] = $v;
          }
        }
        else {
          $accumulatedValues[$fieldName][] = $fieldValue;
        }
      }
      else {
        $accumulatedValues[$fieldName] = $fieldValue;
      }
    }

    foreach ($accumulatedValues as $fieldName => $value) {
      if ($destinationEntity->get($fieldName)->getValue() !== (array) $value) {
        $destinationEntity->set($fieldName, $value);
      }
    }

    return $this->dryRun || $this->saveEntity($destinationEntity);
  }

  /**
   * Prepares the value of the given field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $fieldName
   *   The file name.
   * @param mixed $value
   *   The value to be set.
   * @param string $mainLangCode
   *   The main language code.
   * @param \Drupal\labdoo_migrate\Model\SpecialTypeModel|null $specialType
   *   The special type model.
   *
   * @return mixed
   *   Returns the prepared value.
   *
   * @throws \Exception
   */
  protected function getPreparedFieldValue(
    EntityInterface $entity,
    string $fieldName,
    $value,
    string $mainLangCode,
    ?SpecialTypeModel $specialType
  ) {

    if (!$specialType) {
      if (is_array($value) && isset($value['value'])) {
        $value = $value['value'];
      }

      return $value;
    }

    $specialFieldType = SpecialFieldTypeFactory::get($specialType->getType());
    return $specialFieldType->getValue(
      $value,
      $specialType->getMetadata(),
      $entity,
      $mainLangCode
    );
  }

  /**
   * Checks if the given value is multi value.
   *
   * @param mixed $value
   *   The value.
   *
   * @return bool
   *   Returns TRUE if the value is multi value, otherwise FALSE.
   */
  protected function checkMultiValue($value): bool {

    return is_array($value)
      && count(array_filter(array_keys($value), 'is_string')) === 0;
  }


  /**
   * Checks if a value exists in the target field to prevent duplicates.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field instance.
   * @param mixed $value
   *   The value.
   *
   * @return bool
   *   Returns TRUE if the value exists, FALSE in case not.
   */
  protected function checkIfValueExists(FieldItemListInterface $field, $value): bool {

    if (is_array($value)) {
      if (isset($value['target_id'])) {
        $val = $value['target_id'];
        $key = 'target_id';
      }
      else {
        $val = $value['value'] ?? '';
        $key = 'value';
      }
    }
    else {
      $val = $value;
      $key = 'value';
    }

    foreach ($field->getValue() as $existingValue) {
      $existingVal = $existingValue[$key] ?? $existingValue['value'] ?? '';
      if ((string) $existingVal === (string) $val) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Saves an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be saved.
   *
   * @return bool
   *   Returns the result of the operation.
   */
  protected function saveEntity(EntityInterface $entity): bool {

    try {
      return $entity->save();
    }
    catch (EntityStorageException | \Exception | \Throwable $e) {
      $errorMessage = sprintf(
        'Error updating content with NID %d: %s',
        $entity->id(),
        $e->getMessage()
      );
      $this->logger->error($errorMessage);
    }

    return FALSE;
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
      $sourceId = $entity->get(self::SOURCE_ID_FIELD)->value ?? FALSE;
      if (!$sourceId) {
        $errorMessage = sprintf(
          'Could not retrieve D7 entity from D9 entity %d',
          $entity->id()
        );

        $this->logger->warning($errorMessage);
        continue;
      }
      $processedEntities[$sourceId] = $entity;
    }

    return $processedEntities;
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
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function prepareEntity(
    int $entityId,
    string $contentType,
    string $langCode
  ): EntityInterface {
    $entity = $this->entityTypeManager
      ->getStorage('node')
      ->load($entityId);
    if ($entity !== NULL) {
      return $entity;
    }

    return $this->entityTypeManager
      ->getStorage('node')
      ->create([
        'type' => $contentType,
        'langcode' => $langCode,
        'nid' => $entityId,
      ]);
  }

  /**
   * {@inheritDoc}
   */
  public function setTotalCount(int $total): void {
    $this->totalSourceEntitiesCount = $total;
    $this->mainEntitiesCount = 0;
    $this->translationsCount = 0;
    $this->failingIds = [];
  }

}
