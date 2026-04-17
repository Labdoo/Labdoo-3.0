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
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    TranslationRepositoryInterface $translationRepository
  ) {

    $this->entityTypeManager = $entityTypeManager;
    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $this->translationRepository = $translationRepository;
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
      ++$this->mainEntitiesCount;
      $divisor = $this->totalSourceEntitiesCount > 0 ? $this->totalSourceEntitiesCount : 1;
      $message = sprintf(
        'Processed entity %d (%s) [%d/%d %s%%]',
        $mainEntity->id(),
        $mainLangCode,
        $this->mainEntitiesCount,
        $this->totalSourceEntitiesCount,
        round($this->mainEntitiesCount * 100 / $divisor, 2)
      );
      $this->logger->notice($message);
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
        $this->logger->notice($message);
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
        ->getStorage('node')
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

    $result = TRUE;
    $defaultLangCode = $destinationEntity->language()->getId();

    foreach ($sourceEntity as $langCode => $values) {
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
        $message = sprintf(
          'Updated entity %d (%s)',
          $currentDestinationEntity->id(),
          $langCode
        );
        $this->logger->notice($message);
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

    foreach ($sourceEntity as $sourceIdentifier => $value) {
      /** @var \Drupal\labdoo_migrate\Model\MappingModel $mapping */
      $mapping = $this->mapping[$sourceIdentifier];
      $destination = $mapping->getDestinationField();

      $destinationEntity = $this->setFieldValue(
        $destinationEntity,
        $destination->getFieldName(),
        $value,
        $mainLangCode,
        $destination->getSpecialType()
      );
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
    string $mainLangCode,
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
      $mainLangCode
    );

    // Early return. We need it here because some destination fields are
    // indeed virtual fields so that, they cannot be stored.
    if (!$fieldName) {
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
      if (is_array($value)) {
        foreach ($value as $singleValue) {
          if (!$this->checkIfValueExists($field, $singleValue)) {
            $entity->{$fieldName}->appendItem($singleValue);
          }
        }
      }
      else {
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
      $existingValue = $existingValue['value'] ?? '';
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
