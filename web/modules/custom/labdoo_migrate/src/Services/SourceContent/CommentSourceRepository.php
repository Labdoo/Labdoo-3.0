<?php

namespace Drupal\labdoo_migrate\Services\SourceContent;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\labdoo_migrate\Model\FieldModel;
use Drupal\labdoo_migrate\Model\MappingModel;
use Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager;
use Drupal\labdoo_migrate\Services\DynamicContent\DynamicContentRepositoryInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * The comment source repository.
 */
class CommentSourceRepository implements SourceRepositoryInterface {

  use LoggerAwareTrait;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  private LanguageManagerInterface $languageManager;

  /**
   * The external connection manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager
   */
  private ExternalConnectionManager $externalConnectionManager;

  /**
   * The dynamic content repository.
   *
   * @var \Drupal\labdoo_migrate\Services\DynamicContent\DynamicContentRepositoryInterface
   */
  private DynamicContentRepositoryInterface $dynamicContentRepository;

  /**
   * The content type.
   *
   * @var string
   */
  private string $contentType;

  /**
   * The mapping array.
   *
   * @var array
   */
  private array $mapping;

  /**
   * The from timestamp.
   *
   * @var int|null
   */
  private ?int $fromTimestamp = NULL;

  /**
   * CommentSourceRepository constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager $externalConnectionManager
   *   The external connection manager.
   * @param \Drupal\labdoo_migrate\Services\DynamicContent\DynamicContentRepositoryInterface $dynamicContentRepository
   *   The dynamic content repository.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerChannelFactory,
    ExternalConnectionManager $externalConnectionManager,
    DynamicContentRepositoryInterface $dynamicContentRepository,
    LanguageManagerInterface $languageManager
  ) {
    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $this->externalConnectionManager = $externalConnectionManager;
    $this->dynamicContentRepository = $dynamicContentRepository;
    $this->languageManager = $languageManager;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntities(
    string $contentType,
    array $mapping,
    ?array $entityIds = NULL,
    ?int $fromTimestamp = NULL
  ): array {
    $this->contentType = $contentType;
    $this->mapping = $mapping;
    $this->fromTimestamp = $fromTimestamp;

    if ($entityIds === NULL) {
      $entityIds = $this->getNodesByType($contentType, $mapping, $fromTimestamp);
    }

    $entities = [];
    foreach ($entityIds as $entityId) {
      $entities[] = $this->getEntity($contentType, $mapping, $entityId, $fromTimestamp);
    }

    $this->externalConnectionManager->restoreConnection();

    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntity(
    string $contentType,
    array $mapping,
    int $entityId,
    ?int $fromTimestamp = NULL
  ): array {
    $this->contentType = $contentType;
    $this->mapping = $mapping;
    $this->fromTimestamp = $fromTimestamp;

    $defaultLangcode = $this->languageManager->getDefaultLanguage()->getId();
    $entity = [];
    $entity['metadata']['main_langcode'] = $defaultLangcode;
    $entity[$defaultLangcode] = $this->getFieldValues($entityId);

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getNodesByType(
    ?string $contentType = NULL,
    ?array $mapping = NULL,
    ?int $fromTimestamp = NULL
  ): array {
    if ($contentType !== NULL) {
      $this->contentType = $contentType;
    }
    if ($mapping !== NULL) {
      $this->mapping = $mapping;
    }
    if ($fromTimestamp !== NULL) {
      $this->fromTimestamp = $fromTimestamp;
    }

    $field = new FieldModel('comment', 'cid', 'cid');
    $query = $this->externalConnectionManager
      ->setConnection()
      ->select($field->getTableName(), 'c')
      ->fields('c', [$field->getFieldName()]);

    if ($this->fromTimestamp !== NULL) {
      $ts = (int) $this->fromTimestamp;
      $query->condition($query->orConditionGroup()
        ->condition('created', $ts, '>=')
        ->condition('changed', $ts, '>=')
      );
    }

    $results = $query->execute()->fetchAll();
    $ids = [];
    foreach ($results as $result) {
      $ids[] = (int) $result->cid;
    }

    return $ids;
  }

  /**
   * Retrieves the field values.
   *
   * @param int $entityId
   *   The entity ID.
   *
   * @return array
   *   Returns an array of field values.
   */
  protected function getFieldValues(int $entityId): array {
    $entity = [];

    /** @var \Drupal\labdoo_migrate\Model\MappingModel $mapping */
    foreach ($this->mapping as $mapping) {
      $entity[$mapping->getSourceIdentifier()] = $this->getFieldValue(
        $entityId,
        $mapping
      );
    }

    return $entity;
  }

  /**
   * Filters the values.
   *
   * @param array $entity
   *   The entity values array.
   *
   * @return array
   *   Returns the filtered values.
   */
  protected function filterValues(array $entity): array {
    return $this->filterOverriddenFields($entity);
  }

  /**
   * Filters the empty fields.
   *
   * @param array $entity
   *   The entity values array.
   *
   * @return array
   *   Returns the filtered values.
   */
  protected function filterEmptyFields(array $entity): array {
    return $entity;
  }

  /**
   * Filters the overridden fields.
   *
   * @param array $entity
   *   The entity values array.
   *
   * @return array
   *   Returns the filtered values.
   */
  protected function filterOverriddenFields(array $entity): array {
    /** @var \Drupal\labdoo_migrate\Model\MappingModel $mapping */
    foreach ($this->mapping as $mapping) {
      $overriddenByField = $mapping->getOverriddenByField();
      if (!$overriddenByField) {
        continue;
      }

      $value = $entity[$overriddenByField] ?? NULL;
      if (!$this->hasFieldValue($value)) {
        continue;
      }

      unset($entity[$overriddenByField]);
    }
    return $entity;
  }

  /**
   * Checks if the field has a value.
   *
   * @param mixed $field
   *   The field value.
   *
   * @return bool
   *   Returns TRUE if the field has a value, FALSE otherwise.
   */
  protected function hasFieldValue($field): bool {
    if (!is_array($field)) {
      return $field !== '' && $field !== NULL;
    }

    foreach ($field as $key => $value) {
      if (empty($value)) {
        unset($field[$key]);
      }
    }

    return (bool) count($field);
  }

  /**
   * Retrieves the field value.
   *
   * @param int $entityId
   *   The entity ID.
   * @param \Drupal\labdoo_migrate\Model\MappingModel $mapping
   *   The mapping model.
   *
   * @return mixed
   *   Returns the field value.
   */
  protected function getFieldValue(int $entityId, MappingModel $mapping) {
    $values = [];
    $fields = $mapping->getSourceField();

    /** @var \Drupal\labdoo_migrate\Model\FieldModel $field */
    foreach ($fields as $field) {
      if ($field->isDynamicParagraph()) {
        $dynamicParagraphValue = $this->dynamicContentRepository
          ->getValue($entityId, $field);
        if ($dynamicParagraphValue) {
          $values[$field->getFieldName()] = $dynamicParagraphValue;
        }

        continue;
      }

      $values[$field->getFieldName()] = $this->getSingleMappingValue($entityId, $field);
    }

    $sourceIdentifier = $mapping->getSourceIdentifier();
    if (count($values) === 1) {
      return reset($values);
    }

    return $values;
  }

  /**
   * Retrieves a single mapping value.
   *
   * @param int $entityId
   *   The entity ID.
   * @param \Drupal\labdoo_migrate\Model\FieldModel $field
   *   The field model.
   *
   * @return mixed
   *   Returns the mapping value.
   */
  protected function getSingleMappingValue(int $entityId, FieldModel $field) {
    $results = $this->getQueryResults($field, $entityId);
    return $this->processSingleResult($entityId, $field, $results);
  }

  /**
   * Retrieves the query results.
   *
   * @param \Drupal\labdoo_migrate\Model\FieldModel $field
   *   The field model.
   * @param mixed $search
   *   The search value.
   *
   * @return array
   *   Returns an array of query results.
   */
  protected function getQueryResults(FieldModel $field, $search): array {
    $query = $this->externalConnectionManager
      ->setConnection()
      ->select($field->getTableName(), 't')
      ->fields('t', [$field->getFieldName()]);

    if ($search !== NULL) {
      $query->condition($field->getKeyName(), $search);
    }

    return $query->execute()->fetchAll();
  }

  /**
   * Processes a single result.
   *
   * @param int $entityId
   *   The entity ID.
   * @param \Drupal\labdoo_migrate\Model\FieldModel $field
   *   The field model.
   * @param array $dbResult
   *   The database result.
   *
   * @return mixed
   *   Returns the processed result.
   */
  protected function processSingleResult(int $entityId, FieldModel $field, array $dbResult) {
    if (empty($dbResult)) {
      return NULL;
    }

    $fieldName = $field->getFieldName();
    if (count($dbResult) === 1) {
      $value = $dbResult[0]->$fieldName;
      return $this->processJoin($field, $value);
    }

    $values = [];
    foreach ($dbResult as $row) {
      $values[] = $this->processJoin($field, $row->$fieldName);
    }
    return $values;
  }

  /**
   * Processes the join.
   *
   * @param \Drupal\labdoo_migrate\Model\FieldModel $field
   *   The field model.
   * @param string $id
   *   The ID.
   *
   * @return string|null
   *   Returns the processed join value.
   */
  protected function processJoin(FieldModel $field, string $id): ?string {
    if (!$field->getJoin()) {
      return $id;
    }

    $join = $field->getJoin();
    $result = $this->externalConnectionManager
      ->setConnection()
      ->select($join['table'], 'j')
      ->fields('j', [$join['field']])
      ->condition($join['key'], $id)
      ->execute()
      ->fetch();

    return $result ? $result->{$join['field']} : NULL;
  }

}
