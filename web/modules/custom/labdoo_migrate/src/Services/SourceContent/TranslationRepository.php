<?php

namespace Drupal\labdoo_migrate\Services\SourceContent;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\labdoo_migrate\Model\FieldModel;
use Drupal\labdoo_migrate\Model\TaxonomyModel;
use Drupal\labdoo_migrate\Model\TranslationModel;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager;
use Psr\Log\LoggerAwareTrait;

/**
 * The translation repository.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class TranslationRepository implements TranslationRepositoryInterface {

  use LoggerAwareTrait;

  /**
   * The external connection manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager
   */
  private ExternalConnectionManager $externalConnectionManager;

  /**
   * The table name.
   *
   * @var mixed
   */
  private $tableName;

  /**
   * The ID field name.
   *
   * @var mixed
   */
  private $fieldIdName;

  /**
   * The language field name.
   *
   * @var mixed
   */
  private $fieldLangName;

  /**
   * The key name.
   *
   * @var mixed
   */
  private $keyName;

  /**
   * Cache for original translations.
   *
   * @var array<string, false|string>
   */
  private array $originalTranslationCache = [];

  /**
   * Cache for auxiliary entity IDs.
   *
   * @var array<string, false|int>
   */
  private array $auxiliaryEntityIdCache = [];

  /**
   * Cache for current translation IDs.
   *
   * @var array<string, false|int>
   */
  private array $currentTranslationIdCache = [];

  /**
   * Cache for main translations.
   *
   * @var array<string, false|string>
   */
  private array $mainTranslationCache = [];

  /**
   * TranslationRepository constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager $externalConnectionManager
   *   The external connection manager.
   * @param \Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface $configurationManager
   *   The configuration manager.
   *
   * @throws \Exception
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerChannelFactory,
    ExternalConnectionManager $externalConnectionManager,
    ConfigurationManagerInterface $configurationManager
  ) {

    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $this->externalConnectionManager = $externalConnectionManager;
    $contentTranslationConfig = $configurationManager
      ->getGlobalConfiguration()
      ->getContentTranslationConfig();
    $this->tableName = $contentTranslationConfig['table_name'];
    $this->fieldIdName = $contentTranslationConfig['field_id_name'];
    $this->fieldLangName = $contentTranslationConfig['field_lang_name'];
    $this->keyName = $contentTranslationConfig['key_name'];
  }

  /**
   * {@inheritDoc}
   */
  public function getLangCode($entityId): string {

    $result = $this->externalConnectionManager
      ->setConnection()
      ->select($this->tableName)
      ->fields($this->tableName, [$this->fieldLangName])
      ->condition($this->fieldIdName, $entityId)
      ->execute()
      ->fetch();
    $this->externalConnectionManager->restoreConnection();

    return $result ? $result->language : '';
  }

  /**
   * {@inheritDoc}
   */
  public function getTranslations($entityId, string $mainLangCode): array {

    $result = $this->externalConnectionManager
      ->setConnection()
      ->select($this->tableName)
      ->fields($this->tableName, [$this->fieldIdName, $this->fieldLangName])
      ->condition($this->keyName, $entityId)
      ->condition($this->fieldLangName, $mainLangCode, '!=')
      ->execute()
      ->fetchAllAssoc($this->fieldIdName);
    $this->externalConnectionManager->restoreConnection();

    $translations = [];
    foreach ($result as $id => $item) {
      $translations[] = new TranslationModel(
        $item->{$this->fieldIdName},
        $item->{$this->fieldLangName}
      );
    }

    return $translations;
  }

  /**
   * {@inheritDoc}
   */
  public function getOriginalTranslation(
    int $entityId,
    FieldModel $field,
    string $mainLangCode
  ): string {

    $taxonomyData = $field->getTaxonomy();
    if (!$taxonomyData) {
      return FALSE;
    }

    $originalTranslationCacheKey = implode(':', [
      $entityId,
      $field->getTableName(),
      $field->getFieldName(),
      $taxonomyData->getBaseTable(),
      $taxonomyData->getTranslationField(),
      $taxonomyData->getLanguageField(),
      $taxonomyData->getValueField(),
      $mainLangCode,
    ]);
    if (array_key_exists($originalTranslationCacheKey, $this->originalTranslationCache)) {
      return $this->originalTranslationCache[$originalTranslationCacheKey];
    }

    if (!($auxiliaryEntityId = $this->getAuxiliaryEntityId($entityId, $field))) {
      $this->originalTranslationCache[$originalTranslationCacheKey] = FALSE;
      return FALSE;
    }

    if (!($translationId = $this->getCurrentTranslationId($auxiliaryEntityId, $taxonomyData))) {
      $this->originalTranslationCache[$originalTranslationCacheKey] = FALSE;
      return FALSE;
    }

    $mainTranslation = $this->getMainTranslation(
      $translationId,
      $taxonomyData,
      $mainLangCode
    );

    $this->externalConnectionManager->restoreConnection();

    $this->originalTranslationCache[$originalTranslationCacheKey] = $mainTranslation;

    return $mainTranslation;
  }

  /**
   * Retrieves the auxiliary entity ID.
   *
   * @param int $entityId
   *   The entity ID.
   * @param \Drupal\labdoo_migrate\Model\FieldModel $field
   *   The field model.
   *
   * @return false|int
   *   Returns the auxiliary entity ID.
   */
  protected function getAuxiliaryEntityId(int $entityId, FieldModel $field) {

    $cacheKey = implode(':', [$entityId, $field->getTableName(), $field->getFieldName(), $field->getKeyName()]);
    if (array_key_exists($cacheKey, $this->auxiliaryEntityIdCache)) {
      return $this->auxiliaryEntityIdCache[$cacheKey];
    }

    $auxiliaryEntityId = $this->externalConnectionManager
      ->setConnection()
      ->select($field->getTableName())
      ->fields($field->getTableName(), [$field->getFieldName()])
      ->condition($field->getKeyName(), $entityId)
      ->execute()
      ->fetch();

    $value = $auxiliaryEntityId->{$field->getFieldName()}
      ? (int) $auxiliaryEntityId->{$field->getFieldName()}
      : FALSE;

    $this->auxiliaryEntityIdCache[$cacheKey] = $value;

    return $value;
  }

  /**
   * Retrieves the current translation ID.
   *
   * @param int $entityId
   *   The entity ID.
   * @param \Drupal\labdoo_migrate\Model\TaxonomyModel $taxonomyData
   *   The taxonomy data.
   *
   * @return false|int
   *   Returns the current translation ID.
   */
  protected function getCurrentTranslationId(
    int $entityId,
    TaxonomyModel $taxonomyData
  ) {

    $table = $taxonomyData->getBaseTable();
    $translationField = $taxonomyData->getTranslationField();
    $keyField = $taxonomyData->getKeyField();
    $cacheKey = implode(':', [$entityId, $table, $translationField, $keyField]);
    if (array_key_exists($cacheKey, $this->currentTranslationIdCache)) {
      return $this->currentTranslationIdCache[$cacheKey];
    }

    $translationId = $this->externalConnectionManager
      ->setConnection()
      ->select($table)
      ->fields($table, [$translationField])
      ->condition($keyField, $entityId)
      ->execute()
      ->fetch();

    $value = $translationId->{$translationField}
      ? (int) $translationId->{$translationField}
      : FALSE;

    $this->currentTranslationIdCache[$cacheKey] = $value;

    return $value;
  }

  /**
   * Retrieves the main translation.
   *
   * @param int $translationId
   *   The translation ID.
   * @param \Drupal\labdoo_migrate\Model\TaxonomyModel $taxonomyData
   *   The taxonomy data.
   * @param string $mainLangCode
   *   The main language code.
   *
   * @return false|string
   *   Returns the main translation value.
   */
  protected function getMainTranslation(
    int $translationId,
    TaxonomyModel $taxonomyData,
    string $mainLangCode
  ) {

    $table = $taxonomyData->getBaseTable();
    $translationField = $taxonomyData->getTranslationField();
    $languageField = $taxonomyData->getLanguageField();
    $valueField = $taxonomyData->getValueField();
    $cacheKey = implode(':', [$translationId, $table, $translationField, $languageField, $valueField, $mainLangCode]);
    if (array_key_exists($cacheKey, $this->mainTranslationCache)) {
      return $this->mainTranslationCache[$cacheKey];
    }

    $mainTranslationId = $this->externalConnectionManager
      ->setConnection()
      ->select($table)
      ->fields($table, [$valueField])
      ->condition($translationField, $translationId)
      ->condition($languageField, $mainLangCode)
      ->execute()
      ->fetch();

    $value = $mainTranslationId->{$valueField} ?? FALSE;
    $this->mainTranslationCache[$cacheKey] = $value;

    return $value;
  }

}
