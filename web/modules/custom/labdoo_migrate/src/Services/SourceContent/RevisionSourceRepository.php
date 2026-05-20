<?php

namespace Drupal\labdoo_migrate\Services\SourceContent;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\labdoo_migrate\Model\FieldModel;
use Drupal\labdoo_migrate\Model\MappingModel;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Repository for node revisions from the source database.
 */
class RevisionSourceRepository implements RevisionSourceRepositoryInterface {

  use LoggerAwareTrait;

  /**
   * The external connection manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface
   */
  protected ConnectionManagerInterface $externalConnectionManager;

  /**
   * RevisionSourceRepository constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface $externalConnectionManager
   *   The external connection manager.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerChannelFactory,
    ConnectionManagerInterface $externalConnectionManager
  ) {
    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $this->externalConnectionManager = $externalConnectionManager;
  }

  /**
   * {@inheritdoc}
   */
  public function getRevisionsByNid(int $nid): array {
    $revisions = $this->externalConnectionManager
      ->setConnection()
      ->select('node_revision', 'nr')
      ->fields('nr', ['vid', 'nid', 'uid', 'title', 'log', 'timestamp', 'status'])
      ->condition('nid', $nid)
      ->orderBy('vid', 'ASC')
      ->execute()
      ->fetchAll();

    $this->externalConnectionManager->restoreConnection();

    return $revisions;
  }

  /**
   * {@inheritdoc}
   */
  public function getRevisionCountsByType(string $contentType): array {
    $query = $this->externalConnectionManager
      ->setConnection()
      ->select('node', 'n');
    $query->join('node_revision', 'nr', 'n.nid = nr.nid');
    $query->fields('n', ['nid']);
    $query->addExpression('COUNT(nr.vid)', 'revision_count');
    $query->condition('n.type', $contentType)
      ->condition(
        $this->externalConnectionManager->setConnection()->condition('OR')
          ->condition('n.tnid', 0)
          ->where('n.nid = n.tnid')
      )
      ->groupBy('n.nid');

    $results = $query->execute()->fetchAllKeyed(0, 1);
    $this->externalConnectionManager->restoreConnection();

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function getRevisionFieldData(int $nid, int $vid, array $mapping, string $contentType): array {
    $data = [];
    /** @var \Drupal\labdoo_migrate\Model\MappingModel $mappingModel */
    foreach ($mapping as $mappingModel) {
      $destinationField = $mappingModel->getDestinationField()->getFieldName();
      $sourceFields = $mappingModel->getSourceField();

      if (count($sourceFields) === 1) {
        /** @var \Drupal\labdoo_migrate\Model\FieldModel $field */
        $field = $sourceFields[0];
        $value = $this->getRevisionFieldValue($nid, $vid, $field, $contentType);
        if ($value !== NULL) {
          $data[$destinationField] = $value;
        }
      }
    }

    return $data;
  }

  /**
   * Retrieves the value for a specific field in a specific revision.
   *
   * @param int $nid
   *   The node ID.
   * @param int $vid
   *   The revision ID.
   * @param \Drupal\labdoo_migrate\Model\FieldModel $field
   *   The field model.
   * @param string $contentType
   *   The content type.
   *
   * @return mixed
   *   The field value.
   */
  protected function getRevisionFieldValue(int $nid, int $vid, FieldModel $field, string $contentType) {
    $tableName = $field->getTableName();

    // If it's a field table, we should use field_revision_ instead of field_data_
    if (strpos($tableName, 'field_data_') === 0) {
      $tableName = str_replace('field_data_', 'field_revision_', $tableName);
    }
    elseif (strpos($tableName, 'field_') === 0 && strpos($tableName, 'field_revision_') === FALSE) {
      // Some mappings might already point to field_revision_ or other tables.
      // But usually they point to field_data_X or just field_X (which often means field_data_X in D7 if it's a field)
      // Drupal 7 field tables are field_data_field_name and field_revision_field_name.
    }

    try {
      $query = $this->externalConnectionManager
        ->setConnection()
        ->select($tableName, 't')
        ->fields('t', [$field->getFieldName()]);

      if ($tableName === 'node' || $tableName === 'node_revision') {
        $query->condition('vid', $vid);
      }
      else {
        $query->condition('entity_id', $nid);
        $query->condition('revision_id', $vid);
        $query->condition('entity_type', 'node');
      }

      $result = $query->execute()->fetch();
      $this->externalConnectionManager->restoreConnection();

      if ($result) {
        $value = $result->{$field->getFieldName()};

        // Handle joins if necessary
        if ($field->isJoin()) {
          $value = $this->processJoin($field, $value);
        }

        return $value;
      }
    }
    catch (\Exception $e) {
      $this->externalConnectionManager->restoreConnection();
      // Table might not exist for this revision or content type
    }

    return NULL;
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

    $this->externalConnectionManager->restoreConnection();

    return $value ? $value->{$join->getValueField()} : NULL;
  }

}
