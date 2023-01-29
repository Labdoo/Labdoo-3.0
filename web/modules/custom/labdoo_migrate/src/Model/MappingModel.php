<?php

namespace Drupal\labdoo_migrate\Model;

/**
 * DTO for a files' mapping.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MappingModel {

  /**
   * The source field data.
   *
   * @var array
   */
  private array $sourceField;

  /**
   * The destination field model.
   *
   * @var \Drupal\labdoo_migrate\Model\FieldModel
   */
  private FieldModel $destinationField;

  /**
   * The overridden field.
   *
   * @var string
   */
  private string $overriddenByField;

  /**
   * MappingModel constructor.
   *
   * @param array $sourceField
   *   The source fields array.
   * @param \Drupal\labdoo_migrate\Model\FieldModel $destinationField
   *   The destination field.
   * @param string $overriddenByField
   *   Overridden by field is a mechanism that defines fields priorities.
   *   If this attribute is set, and the referenced field has a value,
   *   the current field won't be considered in the mapping.
   */
  public function __construct(
    array $sourceField,
    FieldModel $destinationField,
    string $overriddenByField = ''
  ) {

    $this->sourceField = $sourceField;
    $this->destinationField = $destinationField;
    $this->overriddenByField = $overriddenByField;
  }

  /**
   * Retrieves the source identifier: table.field.
   *
   * @return string
   *   Returns the source identifier.
   *
   * @throws \Exception
   */
  public function getSourceIdentifier(): string {

    if (!$this->sourceField) {
      throw new \Exception('Source field not defined');
    }

    /** @var \Drupal\labdoo_migrate\Model\FieldModel $sourceField */
    $sourceField = $this->getSourceField()[0];

    return sprintf(
      '%s.%s',
      $sourceField->getTableName(),
      $sourceField->getFieldAlias() ?: $sourceField->getFieldName()
    );
  }

  /**
   * Retrieves the source field array.
   *
   * @return array
   *   Returns the source field array.
   */
  public function getSourceField(): array {

    return $this->sourceField;
  }

  /**
   * Retrieves the destination field model.
   *
   * @return \Drupal\labdoo_migrate\Model\FieldModel
   *   Returns the destination field model.
   */
  public function getDestinationField(): FieldModel {

    return $this->destinationField;
  }

  /**
   * Retrieves the overridden by field value.
   *
   * @return string
   *   Returns the overridden by field value.
   */
  public function getOverriddenByField(): string {

    return $this->overriddenByField;
  }

}
