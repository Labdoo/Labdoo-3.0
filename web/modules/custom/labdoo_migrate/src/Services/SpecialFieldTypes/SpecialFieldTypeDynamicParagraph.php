<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;

/**
 * The special field type for dynamics paragraphs.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypeDynamicParagraph implements SpecialFieldTypeInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The dynamic paragraphs mapping configuration.
   *
   * @var array
   */
  private array $dynamicParagraphsMappingConfig;

  /**
   * The entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  private EntityInterface $entity;

  /**
   * SpecialFieldTypeParagraph constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface $configurationManager
   *   The configuration manager.
   *
   * @throws \Exception
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    ConfigurationManagerInterface $configurationManager
  ) {

    $this->entityTypeManager = $entityTypeManager;
    $this->dynamicParagraphsMappingConfig = $configurationManager
      ->getGlobalConfiguration()
      ->getDynamicParagraphsMappingConfig();
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(
    $value,
    array $metadata,
    EntityInterface $entity,
    string $mainLangCode
  ) {

    if (!is_array($value)) {
      throw new \Exception('A DynamicParagraph value must be an array');
    }

    $this->entity = $entity;
    $result = [];
    foreach ($value as $bundle => $paragraph) {
      foreach ($paragraph as $paragraphFields) {
        if (isset($this->dynamicParagraphsMappingConfig[$bundle])) {
          $result[] = $this->processBundle(
            $bundle,
            $paragraphFields,
            $mainLangCode
          );
        }
      }
    }

    if (count($result) === 1) {
      $result = reset($result);
    }

    return $result;
  }

  /**
   * Processes the bundle.
   *
   * @param string $bundle
   *   The bundle.
   * @param array $fields
   *   The fields array.
   * @param string $mainLangCode
   *   The main language code.
   *
   * @return array
   *   Returns an array of new paragraphs.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  protected function processBundle(
    string $bundle,
    array $fields,
    string $mainLangCode
  ): array {

    $values = [];
    $valuesMapping = [];
    $config = $this->dynamicParagraphsMappingConfig[$bundle];
    foreach ($fields as $fieldName => $value) {
      if (!isset($config['fields_mapping'][$fieldName])) {
        continue;
      }
      if (isset($config['fields_mapping'][$fieldName]['special_type'])) {
        $specialTypeDefinition = $config['fields_mapping'][$fieldName];
        $specialTypeProcessor = SpecialFieldTypeFactory::get($specialTypeDefinition['special_type']);
        $metadata = $specialTypeDefinition['metadata'] ?? [];
        $specialTypeValue = $specialTypeProcessor->getValue(
          $value,
          $metadata,
          $this->entity,
          $mainLangCode
        );
        $values[$specialTypeDefinition['wrapper_field']] = $specialTypeValue;
        $valuesMapping[$specialTypeDefinition['wrapper_field']] = [
          'source_field' => $fieldName,
          'value' => $value,
        ];
        continue;
      }

      $key = $config['fields_mapping'][$fieldName];
      $valuesMapping[$key] = [
        'source_field' => $fieldName,
        'value' => $value,
      ];
      $values[$key] = $value;
    }

    if (isset($specialTypeProcessor)) {
      $values = $this->filterSpecialFieldTypeValues(
        $specialTypeProcessor,
        $values,
        $valuesMapping
      );
    }

    return $this->createParagraph($bundle, $values);
  }

  /**
   * Filters special field type values.
   *
   * There are some certain field types that have high complex logic of
   * precedence of fields (i.e. if one field is present, remove another).
   * Thus, we need a mechanism to invoke this filter capability.
   *
   * @param \Drupal\labdoo_migrate\Services\SpecialFieldTypes\SpecialFieldTypeInterface $specialTypeProcessor
   *   The special type field processor.
   * @param array $values
   *   The values.
   * @param array $valuesMapping
   *   The values mapping.
   *
   * @return array
   *   Returns the filtered values.
   */
  protected function filterSpecialFieldTypeValues(
    SpecialFieldTypeInterface $specialTypeProcessor,
    array $values,
    array $valuesMapping
  ): array {

    $valuesMapping = $specialTypeProcessor->filterValue($valuesMapping);
    $keys = array_keys($valuesMapping);
    foreach ($values as $fieldName => $value) {
      if (!in_array($fieldName, $keys)) {
        unset($value[$fieldName]);
      }
    }

    return $values;
  }

  /**
   * Creates a paragraph.
   *
   * @param string $bundle
   *   The bundle.
   * @param array $values
   *   The values array.
   *
   * @return array
   *   Returns a new paragraph.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  protected function createParagraph(string $bundle, array $values): array {

    $config = $this->dynamicParagraphsMappingConfig[$bundle];
    $entity = $this->entityTypeManager
      ->getStorage('paragraph')
      ->create([
        'type' => $config['destination_paragraph'],
        'langcode' => $this->entity->language()->getId(),
      ]);

    foreach ($values as $fieldName => $value) {
      $entity->set($fieldName, $value);
      if ($entity->get($fieldName)->getFieldDefinition()->getType() === 'text_long') {
        $entity->{$fieldName}->format = 'full_html';
      }
    }

    if (!$entity->save()) {
      $errorMessage = sprintf(
        'Error saving a paragraph with bundle %s',
        $bundle
      );
      throw new \Exception($errorMessage);
    }

    return [
      'target_id' => $entity->id(),
      'target_revision_id' => $entity->getRevisionId(),
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
