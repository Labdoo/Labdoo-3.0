<?php

namespace Drupal\labdoo_migrate\Services\DynamicContent;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\labdoo_migrate\Model\FieldModel;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager;
use Drupal\labdoo_migrate\Services\Media\FileManagerInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * The dynamic paragraph repository.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DynamicParagraphRepository implements DynamicContentRepositoryInterface {

  use LoggerAwareTrait;

  /**
   * The external connection manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager
   */
  private ExternalConnectionManager $externalConnectionManager;

  /**
   * The file manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Media\FileManagerInterface
   */
  private FileManagerInterface $fileManager;

  /**
   * The dynamic paragraphs mapping configuration.
   *
   * @var array
   */
  private array $dynamicParagraphsMappingConfig;

  /**
   * The field model.
   *
   * @var \Drupal\labdoo_migrate\Model\FieldModel
   */
  private FieldModel $field;

  /**
   * DynamicParagraphRepository constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager $externalConnectionManager
   *   The external connection manager.
   * @param \Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface $configurationManager
   *   The configuration manager.
   * @param \Drupal\labdoo_migrate\Services\Media\FileManagerInterface $fileManager
   *   The file manager.
   *
   * @throws \Exception
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerChannelFactory,
    ExternalConnectionManager $externalConnectionManager,
    ConfigurationManagerInterface $configurationManager,
    FileManagerInterface $fileManager
  ) {

    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $this->externalConnectionManager = $externalConnectionManager;
    $this->fileManager = $fileManager;
    $this->dynamicParagraphsMappingConfig = $configurationManager
      ->getGlobalConfiguration()
      ->getDynamicParagraphsMappingConfig();
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(int $entityId, FieldModel $field) {

    $this->field = $field;

    if (!($paragraphIds = $this->getParagraphIds($entityId))) {
      return FALSE;
    }

    $result = [];
    foreach ($paragraphIds as $paragraphId) {
      if (!($paragraphBundle = $this->getParagraphBundle($paragraphId))) {
        continue;
      }

      if (!($paragraphFieldNames = $this->getParagraphFieldNames($paragraphBundle))) {
        continue;
      }

      $paragraphValues = $this->getParagraphValues(
        $paragraphId,
        $paragraphBundle,
        $paragraphFieldNames
      );

      $result[$paragraphBundle][] = $paragraphValues;
    }

    return $result;
  }

  /**
   * Retrieves the paragraph IDs.
   *
   * @param int $entityId
   *   The entity ID.
   *
   * @return array|false|mixed
   *   Returns the paragraph IDs.
   */
  protected function getParagraphIds(int $entityId) {

    $tableName = $this->field->getTableName();
    $fieldName = $this->field->getFieldName();
    $keyName = $this->field->getKeyName();
    $conditions = [$keyName => $entityId];

    return $this->executeQuery($tableName, $fieldName, $conditions, FALSE);
  }

  /**
   * Retrieves the paragraph bundle.
   *
   * @param int $paragraphId
   *   The paragraph ID.
   *
   * @return array|false|mixed
   *   Returns the paragraph bundle.
   */
  protected function getParagraphBundle(int $paragraphId) {

    $tableName = $this->field->getDynamicParagraph()->getBundlesTable();
    $fieldName = $this->field->getDynamicParagraph()->getBundlesField();
    $keyName = $this->field->getDynamicParagraph()->getBundlesKey();
    $conditions = [$keyName => $paragraphId];

    return $this->executeQuery($tableName, $fieldName, $conditions);
  }

  /**
   * Retrieves the paragraph field names.
   *
   * @param string $paragraphBundle
   *   The paragraph bundle.
   *
   * @return array|false|mixed
   *   Returns the paragraph field names.
   */
  protected function getParagraphFieldNames(string $paragraphBundle) {

    $tableName = $this->field->getDynamicParagraph()->getFieldnameTable();
    $fieldName = $this->field->getDynamicParagraph()->getFieldnameField();
    $keyName = $this->field->getDynamicParagraph()->getFieldnameKey();
    $conditions = [$keyName => $paragraphBundle];

    return $this->executeQuery($tableName, $fieldName, $conditions, FALSE);
  }

  /**
   * Retrieves the paragraph values.
   *
   * @param int $paragraphId
   *   The paragraph ID.
   * @param string $paragraphBundle
   *   The paragraph bundles.
   * @param array $paragraphFieldNames
   *   The paragraph field names.
   *
   * @return array
   *   Returns the paragraph values.
   *
   * @throws \Exception
   */
  protected function getParagraphValues(
    int $paragraphId,
    string $paragraphBundle,
    array $paragraphFieldNames
  ): array {

    $keyName = $this->field->getDynamicParagraph()->getValueKey();
    $fieldnameKeyName = $this->field->getDynamicParagraph()->getFieldnameKey();
    $conditions = [
      $keyName => $paragraphId,
      $fieldnameKeyName => $paragraphBundle,
    ];

    $values = [];
    foreach ($paragraphFieldNames as $paragraphFieldName) {
      $value = $this->processFieldSuffixes($paragraphFieldName, $conditions);
      if (!$value) {
        continue;
      }

      $values[$paragraphFieldName] = $this->processSourceTransformations(
        $paragraphBundle,
        $paragraphFieldName,
        $value
      );

      $values[$paragraphFieldName] = $this->processSpecialCases(
        $paragraphBundle,
        $paragraphFieldName,
        $values[$paragraphFieldName]
      );
    }

    return $values;
  }

  /**
   * Processes the field suffixes.
   *
   * We need this fallback since the Drupal data model does not provide any
   * clues on the actual field name.
   *
   * @param string $paragraphFieldName
   *   The field name.
   * @param array $conditions
   *   The conditions array.
   *
   * @return array|false|mixed
   *   Returns the field value.
   */
  protected function processFieldSuffixes(
    string $paragraphFieldName,
    array $conditions
  ) {

    $tablePrefix = $this->field->getDynamicParagraph()->getValueTablePrefix();
    $fieldSuffixCollection = $this->field->getDynamicParagraph()->getValueFieldSuffix();

    foreach ($fieldSuffixCollection as $fieldSuffix) {
      $tableName = sprintf('%s%s', $tablePrefix, $paragraphFieldName);
      $fieldName = sprintf('%s%s', $paragraphFieldName, $fieldSuffix);
      try {
        return $this->executeQuery($tableName, $fieldName, $conditions);
      }
      catch (DatabaseExceptionWrapper $e) {
      }
    }

    return FALSE;
  }

  /**
   * Processes the source transformations.
   *
   * @param string $paragraphBundle
   *   The paragraph bundle.
   * @param string $paragraphFieldName
   *   The paragraph field name.
   * @param mixed $value
   *   The paragraph value.
   *
   * @return array|false|mixed
   *   Returns the transformed value.
   *
   * @throws \Exception
   */
  protected function processSourceTransformations(
    string $paragraphBundle,
    string $paragraphFieldName,
    $value
  ) {

    if (!isset($this->dynamicParagraphsMappingConfig[$paragraphBundle])) {
      return $value;
    }

    $config = $this->dynamicParagraphsMappingConfig[$paragraphBundle];
    if (
      !isset($config['fields_mapping'][$paragraphFieldName])
      || !isset($config['fields_mapping'][$paragraphFieldName]['source_transformation'])
    ) {
      return $value;
    }

    $sourceTransformations = $config['fields_mapping'][$paragraphFieldName]['source_transformation'];
    $tableName = $sourceTransformations['table'];
    $fieldName = $sourceTransformations['value_field'];
    $keyName = $sourceTransformations['key_field'];
    $conditions = [$keyName => $value];

    $result = $this->executeQuery($tableName, $fieldName, $conditions);

    return $this->processFile($result, $sourceTransformations);
  }

  /**
   * Processes special cases.
   *
   * @param string $paragraphBundle
   * @param string $paragraphFieldName
   * @param $value
   *
   * @return mixed
   * @throws \Exception
   */
  protected function processSpecialCases(
    string $paragraphBundle,
    string $paragraphFieldName,
    $value
  ) {

    if (!isset($this->dynamicParagraphsMappingConfig[$paragraphBundle])) {
      return $value;
    }

    $config = $this->dynamicParagraphsMappingConfig[$paragraphBundle];
    if (
      !isset($config['fields_mapping'][$paragraphFieldName])
      || !isset($config['fields_mapping'][$paragraphFieldName]['special_case'])
    ) {
      return $value;
    }

    $specialCase = $config['fields_mapping'][$paragraphFieldName]['special_case'];
    if ($specialCase !== 'subparagraph') {
      return $value;
    }

    $collection = is_array($value) ? $value : [$value];
    $subParagraphs = [];
    foreach ($collection as $index => $itemValue) {
      $subParagraphBundle = $this->getParagraphBundle($itemValue);
      $subParagraphFieldNames = $this->getParagraphFieldNames($subParagraphBundle);
      $subParagraphs[$subParagraphBundle][] = $this->getParagraphValues(
        $itemValue,
        $subParagraphBundle,
        $subParagraphFieldNames
      );
    }

    return $subParagraphs;
  }

  /**
   * Processes a file.
   *
   * @param mixed $value
   *   The processed value.
   * @param array $sourceTransformations
   *   The source transformations data.
   *
   * @return array|string
   *   Returns the processed file(s).
   *
   * @throws \Exception
   */
  protected function processFile($value, array $sourceTransformations) {

    $isFile = isset($sourceTransformations['is_file'])
      && $sourceTransformations['is_file'] == 'true';
    if (!$isFile) {
      return $value;
    }

    if (!is_array($value)) {
      return $this->fileManager->getFileContents($value);
    }

    $files = [];
    foreach ($value as $fileUri) {
      $files[] = $this->fileManager->getFileContents($fileUri);
    }

    return $files;
  }

  /**
   * Executes a query.
   *
   * @param string $tableName
   *   The table name.
   * @param string $fieldName
   *   The field name.
   * @param array $conditions
   *   The conditions array.
   * @param bool $flatten
   *   Flattens the result array.
   *
   * @return array|false|mixed
   *   Returns the query result.
   */
  protected function executeQuery(
    string $tableName,
    string $fieldName,
    array $conditions,
    bool $flatten = TRUE
  ) {

    $query = $this->externalConnectionManager
      ->setConnection()
      ->select($tableName)
      ->fields($tableName, [$fieldName]);

    foreach ($conditions as $key => $value) {
      if (is_array($value)) {
        $query->condition($key, $value, 'IN');
      }
      else {
        $query->condition($key, $value);
      }
    }

    $result = $query->execute()->fetchAll();
    $processed = [];
    foreach ($result as $value) {
      if (isset($value->{$fieldName})) {
        $processed[] = $value->{$fieldName};
      }
    }

    if (!$flatten) {
      return $processed;
    }

    return count($processed) === 1 ? reset($processed) : $processed;
  }

}
