<?php

namespace Drupal\labdoo_migrate\Services\Mapper;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\labdoo_migrate\Model\DynamicParagraphModel;
use Drupal\labdoo_migrate\Model\MappingModel;
use Drupal\labdoo_migrate\Model\JoinModel;
use Drupal\labdoo_migrate\Model\FieldModel;
use Drupal\labdoo_migrate\Model\SpecialTypeModel;
use Drupal\labdoo_migrate\Model\TaxonomyModel;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\labdoo_migrate\Services\SpecialFieldTypes\SpecialFieldTypeFactory;
use Psr\Log\LoggerAwareTrait;

/**
 * The mapper for fields.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class FieldsMapper implements MapperInterface {

  use LoggerAwareTrait;

  /**
   * The configuration manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface
   */
  private ConfigurationManagerInterface $configurationManager;

  /**
   * The mapping array.
   *
   * @var array
   */
  private array $mapping;

  /**
   * FieldsMapper constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface $configurationManager
   *   The configuration manager.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerChannelFactory,
    ConfigurationManagerInterface $configurationManager
  ) {

    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $this->configurationManager = $configurationManager;
    $this->mapping = [];
  }

  /**
   * {@inheritDoc}
   */
  public function buildMapping(array $fieldsMapping): array {

    foreach ($fieldsMapping as $fieldMapping) {
      $source = $fieldMapping['source'];
      $destination = $fieldMapping['destination'];
      $specialType = $this->buildSpecialType($destination);
      $overriddenByField = $fieldMapping['overridden_by_field'] ?? '';
      $this->buildSingleMapping($source, $destination, $specialType, $overriddenByField);
    }

    return $this->mapping;
  }

  /**
   * Builds a single mapping.
   *
   * @param array $source
   *   The source array.
   * @param array $destination
   *   The destination array.
   * @param \Drupal\labdoo_migrate\Model\SpecialTypeModel|null $specialType
   *   The Special Type model.
   * @param string $overriddenByField
   *   Overridden by field is a mechanism that defines fields priorities.
   *   If this attribute is set, and the referenced field has a value,
   *   the current field won't be considered in the mapping.
   *
   * @throws \Exception
   */
  protected function buildSingleMapping(
    array $source,
    array $destination,
    ?SpecialTypeModel $specialType = NULL,
    string $overriddenByField = ''
  ): void {

    $tableName = '';
    $sourceFields = [];
    foreach ($source['fields'] as $field) {
      $dynamicParagraph = $this->buildDynamicParagraph($field);
      $join = $this->buildJoin($field);
      $taxonomy = $this->buildTaxonomy($field);

      $sourceFields[] = new FieldModel(
        $field['table'],
        $field['key'],
        $field['field'],
        $field['expression'] ?? '',
        $field['joins'] ?? [],
        $field['ignore_langcode'] ?? FALSE,
        $field['field_alias'] ?? '',
        $field['operator'] ?? '=',
        $field['filter_prefix'] ?? '',
        $field['filter_suffix'] ?? '',
        $field['default_value'] ?? NULL,
        $dynamicParagraph,
        $join,
        $taxonomy,
        NULL
      );
      if (!$tableName) {
        $tableName = $field['table'];
      }
    }

    if ($sourceFields) {
      $mappingModel = new MappingModel(
        $sourceFields,
        new FieldModel(
          '',
          '',
          $destination['field'] ?? '',
          '',
          [],
          FALSE,
          '',
          '=',
          '',
          '',
          NULL,
          NULL,
          NULL,
          NULL,
          $specialType
        ),
        $overriddenByField
      );
      $sourceIdentifier = $mappingModel->getSourceIdentifier();
      if (isset($this->mapping[$sourceIdentifier])) {
        $sourceIdentifier .= '_' . $mappingModel->getDestinationField()->getFieldName();
      }
      $this->mapping[$sourceIdentifier] = $mappingModel;
    }
  }

  /**
   * Builds a Dynamic Paragraph model.
   *
   * @param array $fieldData
   *   The field data.
   *
   * @return \Drupal\labdoo_migrate\Model\DynamicParagraphModel|null
   *   Returns a Dynamic Paragraph model.
   *
   * @throws \Exception
   */
  protected function buildDynamicParagraph(array $fieldData): ?DynamicParagraphModel {

    if (
      !isset($fieldData['dynamic_paragraph'])
    ) {
      return NULL;
    }

    $dynamicParagraphsSourceConfig = $this->configurationManager
      ->getGlobalConfiguration()
      ->getDynamicParagraphsSourceConfig();

    return new DynamicParagraphModel(
      $dynamicParagraphsSourceConfig['bundles']['table'],
      $dynamicParagraphsSourceConfig['bundles']['field'],
      $dynamicParagraphsSourceConfig['bundles']['key'],
      $dynamicParagraphsSourceConfig['fieldname']['table'],
      $dynamicParagraphsSourceConfig['fieldname']['field'],
      $dynamicParagraphsSourceConfig['fieldname']['key'],
      $dynamicParagraphsSourceConfig['value']['table_prefix'],
      $dynamicParagraphsSourceConfig['value']['field_suffix'],
      $dynamicParagraphsSourceConfig['value']['key']
    );
  }

  /**
   * Builds a Join model.
   *
   * @param array $fieldData
   *   The field data.
   *
   * @return \Drupal\labdoo_migrate\Model\JoinModel|null
   *   Returns a Join model.
   */
  protected function buildJoin(array $fieldData): ?JoinModel {

    if (!isset($fieldData['join'])) {
      return NULL;
    }

    return new JoinModel(
      $fieldData['join']['table'],
      $fieldData['join']['key_field'],
      $fieldData['join']['value_field']
    );
  }

  /**
   * Builds a Taxonomy model.
   *
   * @param array $fieldData
   *   The field data.
   *
   * @return \Drupal\labdoo_migrate\Model\TaxonomyModel|null
   *   Returns a Taxonomy model.
   */
  protected function buildTaxonomy(array $fieldData): ?TaxonomyModel {

    if (!isset($fieldData['taxonomy'])) {
      return NULL;
    }

    return new TaxonomyModel(
      $fieldData['taxonomy']['base_table'],
      $fieldData['taxonomy']['key_field'],
      $fieldData['taxonomy']['value_field'],
      $fieldData['taxonomy']['language_field'],
      $fieldData['taxonomy']['translation_field']
    );
  }

  /**
   * Builds a Special Type model.
   *
   * @param array $fieldData
   *   The field data array.
   *
   * @return \Drupal\labdoo_migrate\Model\SpecialTypeModel|null
   *   Returns the Special Type model.
   */
  protected function buildSpecialType(array $fieldData): ?SpecialTypeModel {

    if (!isset($fieldData['special_type'])) {
      return NULL;
    }

    $specialType = $fieldData['special_type']['type'];
    try {
      SpecialFieldTypeFactory::get($specialType);
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Invalid special type %s for field %s',
        $specialType,
        $fieldData['field']
      );
      $this->logger->error($errorMessage);

      return NULL;
    }

    return new SpecialTypeModel(
      $fieldData['special_type']['type'],
      $fieldData['special_type']['metadata'] ?? NULL
    );
  }

}
