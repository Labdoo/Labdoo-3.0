<?php

namespace Drupal\labdoo_migrate\Services\SourceContent;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\labdoo_migrate\Model\FieldModel;
use Drupal\labdoo_migrate\Model\MappingModel;
use Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager;
use Drupal\labdoo_migrate\Services\DynamicContent\DynamicContentRepositoryInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * The user repository.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class UserSourceRepository implements SourceRepositoryInterface {

  use LoggerAwareTrait;

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
   * Optional timestamp filter for created/access fields (users).
   *
   * @var int|null
   */
  private ?int $fromTimestamp = NULL;

  /**
   * SourceRepository constructor.
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
    DynamicContentRepositoryInterface $dynamicContentRepository
  ) {

    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $this->externalConnectionManager = $externalConnectionManager;
    $this->dynamicContentRepository = $dynamicContentRepository;
  }

  /**
   * {@inheritDoc}
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
    $entities = [];
    if (empty($entityIds)) {
      $entityIds = $this->getNodesByType();
    }

    /** @var \Drupal\labdoo_migrate\Model\FieldModel $field */
    foreach ($entityIds as $entityId) {
      $entities[$entityId] = $this->getEntity($contentType, $mapping, $entityId, $fromTimestamp);
    }

    $this->externalConnectionManager->restoreConnection();

    return $entities;
  }

  /**
   * {@inheritDoc}
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

    return $this->getFieldValues($entityId);
  }

  /**
   * {@inheritDoc}
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

    $field = new FieldModel('users', 'uid', 'uid');
    // Build a basic select with optional date filter.
    $query = $this->externalConnectionManager
      ->setConnection()
      ->select($field->getTableName())
      ->fields($field->getTableName(), [$field->getFieldName()]);
    if ($this->fromTimestamp !== NULL) {
      $ts = (int) $this->fromTimestamp;
      // Drupal 7 users table: use created and access fields as activity markers.
      $or = $query->orConditionGroup()
        ->condition('created', $ts, '>=')
        ->condition('access', $ts, '>=');
      $query->condition($or);
    }
    $results = $query->execute()->fetchAll();
    $ids = [];
    foreach ($results as $result) {
      $ids[] = $result->uid;
    }

    return $ids;
  }

  /**
   * Retrieves the field values.
   *
   * @param mixed $entityId
   *   The entity ID.
   *
   * @return array
   *   Returns an array of field values.
   *
   * @throws \Exception
   */
  protected function getFieldValues($entityId): array {

    $entity = [];

    /** @var \Drupal\labdoo_migrate\Model\MappingModel $mapping */
    foreach ($this->mapping as $mapping) {
      $entity[$mapping->getSourceIdentifier()] = $this->getFieldValue(
        $entityId,
        $mapping
      );
    }

    return $this->filterValues($entity);
  }

  /**
   * Filters the values.
   *
   * @param array $entity
   *   The entity values array.
   *
   * @return array
   *   Returns the filtered values.
   *
   * @throws \Exception
   */
  protected function filterValues(array $entity): array {

    $entity = $this->filterEmptyFields($entity);

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
   *
   * @throws \Exception
   */
  protected function filterEmptyFields(array $entity): array {

    /** @var \Drupal\labdoo_migrate\Model\MappingModel $mapping */
    foreach ($this->mapping as $mapping) {
      $mappingId = $mapping->getSourceIdentifier();
      if (!$this->hasFieldValue($entity[$mappingId])) {
        unset($entity[$mappingId]);
      }
    }

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
   *
   * @throws \Exception
   */
  protected function filterOverriddenFields(array $entity): array {

    /** @var \Drupal\labdoo_migrate\Model\MappingModel $mapping */
    foreach ($this->mapping as $mapping) {
      $overriddenByField = $mapping->getOverriddenByField();
      if (!$overriddenByField) {
        continue;
      }

      $value = $entity[$overriddenByField];
      if (!$this->hasFieldValue($value)) {
        continue;
      }

      unset($entity[$overriddenByField]);
    }

    return $entity;
  }

  /**
   * Checks if a field has a value.
   *
   * @param mixed $field
   *   The field.
   *
   * @return bool
   *   Returns TRUE if the field has a value, otherwise FALSE.
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

    return count($field);
  }

  /**
   * Retrieves the value of a field.
   *
   * @param int $entityId
   *   The entity ID.
   * @param \Drupal\labdoo_migrate\Model\MappingModel $mapping
   *   The mapping model.
   *
   * @return string|array
   *   Returns the field value.
   *
   * @throws \Exception
   */
  protected function getFieldValue(
    int $entityId,
    MappingModel $mapping
  ) {

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

      $values[$field->getFieldName()] = $this->getSingleMappingValue(
        $entityId,
        $field
      );
    }

    if (count($values) === 1) {
      return reset($values);
    }

    return $values;
  }

  /**
   * Retrieves the value of a single mapping.
   *
   * @param int $entityId
   *   The entity ID.
   * @param \Drupal\labdoo_migrate\Model\FieldModel $field
   *   The field model.
   *
   * @return array|string
   *   Returns the single mapping value.
   *
   * @throws \Exception
   */
  protected function getSingleMappingValue(
    int $entityId,
    FieldModel $field
  ) {

    if ($field->getDefaultValue()) {
      return $field->getDefaultValue();
    }

    $search = $field->getOperator() === '=' ?
      $entityId :
      sprintf('%%%s%%', $entityId);
    $search = sprintf(
      '%s%s%s',
      $field->getFilterPrefix(),
      $search,
      $field->getFilterSuffix()
    );

    try {
      $dbResults = $this->getQueryResults($field, $search);
    }
    catch (\Throwable $e) {
      return NULL;
    }

    $value = [];
    foreach ($dbResults as $dbResult) {
      $value[] = $this->processSingleResult(
        $entityId,
        $field,
        $dbResult
      );
    }
    if (count($value) === 1) {
      $value = reset($value);
    }

    return $value;
  }

  /**
   * Retrieves the results of the query.
   *
   * @param \Drupal\labdoo_migrate\Model\FieldModel $field
   *   The field model.
   * @param mixed|null $search
   *   The search value.
   *
   * @return array
   *   Returns the results of the query.
   */
  protected function getQueryResults(
    FieldModel $field,
    $search = NULL
  ): array {

    $query = $this->externalConnectionManager
      ->setConnection()
      ->select($field->getTableName());

    if (!empty($field->getExpression())) {
      $query->addExpression($field->getExpression(), $field->getFieldName());
    }
    else {
      $query->fields($field->getTableName(), [$field->getFieldName()]);
    }

    if (!empty($field->getJoins())) {
      foreach ($field->getJoins() as $join) {
        $query->addJoin($join['type'], $join['table'], $join['table'], $join['condition']);
      }
    }

    if ($search !== NULL) {
      $query->condition($field->getKeyName(), $search, $field->getOperator());
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
   * @param mixed $dbResult
   *   The database result to process.
   *
   * @return array|string|null
   *   Returns the processed value.
   *
   * @throws \Exception
   */
  protected function processSingleResult(
    int $entityId,
    FieldModel $field,
    $dbResult
  ) {

    if (!$dbResult || !property_exists($dbResult, $field->getFieldName())) {
      $errorMessage = sprintf(
        'Could not retrieve the field %s for the entity_id %d',
        $field->getFieldName(),
        $entityId
      );
      $this->logger->warning($errorMessage);

      return '';
    }

    $value = $dbResult->{$field->getFieldName()};
    if ($field->isJoin()) {
      $value = $this->processJoin($field, $value);
    }

    return $value;
  }

  /**
   * Processes the Join.
   *
   * @param \Drupal\labdoo_migrate\Model\FieldModel $field
   *   The field model.
   * @param string $id
   *   The ID.
   *
   * @return string|null
   *   Returns the value of the join.
   *
   * @throws \Exception
   */
  protected function processJoin(FieldModel $field, string $id): ?string {

    $join = $field->getJoin();
    $value = $this->externalConnectionManager
      ->setConnection()
      ->select($join->getTable())
      ->fields($join->getTable(), [$join->getValueField()])
      ->condition($join->getKeyField(), $id)
      ->execute()
      ->fetch();

    return $value->{$join->getValueField()};
  }

}
