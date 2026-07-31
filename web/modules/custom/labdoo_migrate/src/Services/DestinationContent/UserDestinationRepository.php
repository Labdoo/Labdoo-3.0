<?php

namespace Drupal\labdoo_migrate\Services\DestinationContent;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityChangedInterface;
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
 * The user repository.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class UserDestinationRepository implements DestinationRepositoryInterface {

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
   * The indexing mode.
   *
   * @var bool
   */
  protected bool $indexingEnabled = TRUE;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * DestinationRepository constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface $migrationTracker
   *   The migration tracker.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    MigrationTrackerInterface $migrationTracker,
    $state
  ) {

    $this->entityTypeManager = $entityTypeManager;
    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $this->migrationTracker = $migrationTracker;
    $this->state = $state;
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

    $properties = [];
    if ($nids) {
      $properties['uid'] = $nids;
    }
    $entities = $this->entityTypeManager
      ->getStorage('user')
      ->loadByProperties($properties);

    return $this->addSourceIdsAsKeys($entities);
  }

  /**
   * {@inheritDoc}
   */
  public function getProcessedEntitiesSummary(): array {
    return [
      'main' => $this->mainEntitiesCount,
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
    if ($this->totalSourceEntitiesCount === 0 || $this->totalSourceEntitiesCount < count($sourceEntities)) {
      $this->totalSourceEntitiesCount = count($sourceEntities);
    }

    $this->disableEntityStorageCache();
    $createdEntities = 0;

    foreach ($sourceEntities as $entityId => $sourceEntity) {
      if ($this->createUser($sourceEntity, $entityId)) {
        ++$createdEntities;
      }
    }

    return $createdEntities;
  }

  /**
   * Creates the user.
   *
   * @param array $sourceEntity
   *   The source entities array.
   * @param int|null $entityId
   *   The source entity ID.
   *
   * @return bool
   *   Returns the result of the process.
   *
   * @throws \Exception
   */
  protected function createUser(
    array $sourceEntity,
    ?int $entityId
  ): bool {

    $startedAt = microtime(TRUE);
    $result = TRUE;

    // Creates the main entity.
    $mainEntity = $this->prepareEntity($entityId);
    if ($entityId !== NULL) {
      $mainEntity->original_entity_id = $entityId;
    }

    $result = $result && $this->updateEntity(
        $sourceEntity,
        $mainEntity
      );

    if ($result) {
      if ($entityId !== NULL && !$this->dryRun) {
        $durationMs = (int) round((microtime(TRUE) - $startedAt) * 1000);
        $this->migrationTracker->track('user', 'user', $entityId, (int) $mainEntity->id(), $durationMs);
      }

      ++$this->mainEntitiesCount;
      $divisor = $this->totalSourceEntitiesCount > 0 ? $this->totalSourceEntitiesCount : 1;
      $message = sprintf(
        'Processed user %d [%d/%d %f%%]',
        $mainEntity->id(),
        $this->mainEntitiesCount,
        $this->totalSourceEntitiesCount,
        round($this->mainEntitiesCount * 100 / $divisor, 2)
      );
      $this->logger->notice($message);
    }
    else {
      $this->failingIds[] = $mainEntity->id();
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
    if ($this->totalSourceEntitiesCount === 0 || $this->totalSourceEntitiesCount < count($sourceEntities)) {
      $this->totalSourceEntitiesCount = count($sourceEntities);
    }

    $updatedEntities = 0;
    $this->disableEntityStorageCache();

    foreach ($sourceEntities as $entityId => $sourceEntity) {
      if ($this->updateUsers($sourceEntity, $destinationEntities[$entityId])) {
        ++$updatedEntities;
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
    try {
      $entityType = $this->entityTypeManager
        ->getStorage('user')
        ->getEntityType();
      $entityType->set('static_cache', FALSE);
      $entityType->set('persistent_cache', FALSE);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $errorMessage = sprintf(
        'Error disabling the entity storage cache: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);
    }
  }

  /**
   * Updates the users.
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
  protected function updateUsers(
    array $sourceEntity,
    EntityInterface $destinationEntity
  ): bool {

    $startedAt = microtime(TRUE);
    $result = $this->updateEntity(
        $sourceEntity,
        $destinationEntity
      );

    if ($result) {
      $sourceId = (int) $destinationEntity->id();
      if ($sourceId > 0 && !$this->dryRun) {
        $durationMs = (int) round((microtime(TRUE) - $startedAt) * 1000);
        $this->migrationTracker->track('user', 'user', $sourceId, (int) $destinationEntity->id(), $durationMs);
      }

      ++$this->mainEntitiesCount;
      $divisor = $this->totalSourceEntitiesCount > 0 ? $this->totalSourceEntitiesCount : 1;
      $message = sprintf(
        'Updated user %d [%d/%d %f%%]',
        $destinationEntity->id(),
        $this->mainEntitiesCount,
        $this->totalSourceEntitiesCount,
        round($this->mainEntitiesCount * 100 / $divisor, 2)
      );
      $this->logger->notice($message);
    }
    else {
      $this->failingIds[] = $destinationEntity->id();
    }

    return $result;
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
    EntityInterface $destinationEntity
  ): bool {

    if (empty($sourceEntity)) {
      return FALSE;
    }

    foreach ($sourceEntity as $sourceIdentifier => $value) {
      /** @var \Drupal\labdoo_migrate\Model\MappingModel $mapping */
      $mapping = $this->mapping[$sourceIdentifier];
      $destination = $mapping->getDestinationField();

      $this->setFieldValue(
        $destinationEntity,
        $destination->getFieldName(),
        $value,
        $destination->getSpecialType()
      );
    }

    if ($destinationEntity instanceof EntityChangedInterface && isset($sourceEntity['changed'])) {
      $destinationEntity->setChangedTime($sourceEntity['changed']);
    }

    return $this->dryRun || $this->saveEntity($destinationEntity);
  }

  /**
   * Sets the value of the given field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $fieldName
   *   The file name.
   * @param mixed $value
   *   The value to be set.
   * @param \Drupal\labdoo_migrate\Model\SpecialTypeModel|null $specialType
   *   The special type model.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Returns the updated entity.
   *
   * @throws \Exception
   */
  protected function setFieldValue(
    EntityInterface $entity,
    string $fieldName,
    $value,
    ?SpecialTypeModel $specialType
  ): EntityInterface {

    if (!$specialType) {
      if (is_array($value) && isset($value['value'])) {
        $value = $value['value'];
      }
      $this->setOrAppend($entity, $fieldName, $value);

      return $entity;
    }

    $specialFieldType = SpecialFieldTypeFactory::get($specialType->getType());
    $value = $specialFieldType->getValue(
      $value,
      $specialType->getMetadata(),
      $entity,
      ''
    );

    // Early return. We need it here because some destination fields are
    // indeed virtual fields so that, they cannot be stored.
    if (!$fieldName || $value === NULL) {
      return $entity;
    }

    if (!$this->checkMultiValue($value)) {
      $this->setOrAppend($entity, $fieldName, $value);

      return $entity;
    }

    foreach ($value as $singleValue) {
      $this->setOrAppend($entity, $fieldName, $singleValue);
    }

    return $entity;
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
   * Sets or appends a value according to the field type.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $fieldName
   *   The field name.
   * @param mixed $value
   *   The value.
   */
  protected function setOrAppend(
    EntityInterface $entity,
    string $fieldName,
    $value
  ) {

    $field = $entity->get($fieldName);
    if ($entity->getFieldDefinition($fieldName)->getFieldStorageDefinition()->isMultiple()) {
      if (!$this->checkIfValueExists($field, $value)) {
        $entity->{$fieldName}->appendItem($value);
      }
    }
    else {
      $entity->set($fieldName, $value);
    }
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

    foreach ($field->getValue() as $existingValue) {
      $existingValue = $existingValue['value'] ?? ($existingValue['target_id'] ?? '');
      if ($existingValue === $value) {
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

    $entity->labdoo_skip_geocoding = TRUE;

    try {
      return $entity->save();
    }
    catch (EntityStorageException | \Exception | \Throwable $e) {
      $errorMessage = sprintf(
        'Error updating content with UID %d: %s',
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
      $sourceId = $entity->id();
      if ($entity->hasField('original_entity_id') && !empty($entity->get('original_entity_id')->value)) {
        $sourceId = (int) $entity->get('original_entity_id')->value;
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
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function prepareEntity(
    int $entityId
  ): EntityInterface {
    $entity = $this->entityTypeManager
      ->getStorage('user')
      ->load($entityId);
    if ($entity !== NULL) {
      return $entity;
    }

    return $this->entityTypeManager
      ->getStorage('user')
      ->create([
        'uid' => $entityId,
      ]);
  }

  /**
   * {@inheritDoc}
   */
  public function setTotalCount(int $total): void {
    if ($this->totalSourceEntitiesCount === $total) {
      return;
    }
    $this->totalSourceEntitiesCount = $total;
    $this->mainEntitiesCount = 0;
    $this->failingIds = [];
  }

  /**
   * {@inheritdoc}
   */
  public function setIndexingMode(bool $indexingEnabled): void {
    $this->indexingEnabled = $indexingEnabled;
    $this->state->set('labdoo_migrate.disable_indexing', !$indexingEnabled);
  }

  /**
   * {@inheritdoc}
   */
  public function setBatchSize(int $batchSize): void {
    // Not implemented for users yet, but required by interface.
  }

}
