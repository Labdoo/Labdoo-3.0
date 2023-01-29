<?php

namespace Drupal\labdoo_migrate\Model;

/**
 * DTO for a dynamic paragraph.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DynamicParagraphModel {

  /**
   * The bundles' table.
   *
   * @var string
   */
  private string $bundlesTable;

  /**
   * The bundles field.
   *
   * @var string
   */
  private string $bundlesField;

  /**
   * The bundles key.
   *
   * @var string
   */
  private string $bundlesKey;

  /**
   * The field name table.
   *
   * @var string
   */
  private string $fieldnameTable;

  /**
   * The field name field.
   *
   * @var string
   */
  private string $fieldnameField;

  /**
   * The field name key.
   *
   * @var string
   */
  private string $fieldnameKey;

  /**
   * The value table prefix.
   *
   * @var string
   */
  private string $valueTablePrefix;

  /**
   * The value field suffix.
   *
   * @var array
   */
  private array $valueFieldSuffix;

  /**
   * The value key.
   *
   * @var string
   */
  private string $valueKey;

  /**
   * DynamicParagraphModel constructor.
   *
   * @param string $bundlesTable
   *   The bundles' table.
   * @param string $bundlesField
   *   The bundles' field.
   * @param string $bundlesKey
   *   The bundles' key.
   * @param string $fieldnameTable
   *   The fieldname table.
   * @param string $fieldnameField
   *   The fieldname field.
   * @param string $fieldnameKey
   *   The fieldname key.
   * @param string $valueTablePrefix
   *   The value table prefix.
   * @param array $valueFieldSuffix
   *   The value field suffix.
   * @param string $valueKey
   *   The value key.
   */
  public function __construct(
    string $bundlesTable,
    string $bundlesField,
    string $bundlesKey,
    string $fieldnameTable,
    string $fieldnameField,
    string $fieldnameKey,
    string $valueTablePrefix,
    array $valueFieldSuffix,
    string $valueKey
  ) {

    $this->bundlesTable = $bundlesTable;
    $this->bundlesField = $bundlesField;
    $this->bundlesKey = $bundlesKey;
    $this->fieldnameTable = $fieldnameTable;
    $this->fieldnameField = $fieldnameField;
    $this->fieldnameKey = $fieldnameKey;
    $this->valueTablePrefix = $valueTablePrefix;
    $this->valueFieldSuffix = $valueFieldSuffix;
    $this->valueKey = $valueKey;
  }

  /**
   * Retrieves the bundles' table.
   *
   * @return string
   *   Returns the bundles' table.
   */
  public function getBundlesTable(): string {

    return $this->bundlesTable;
  }

  /**
   * Retrieves the bundles' field.
   *
   * @return string
   *   Returns the bundles' field.
   */
  public function getBundlesField(): string {

    return $this->bundlesField;
  }

  /**
   * Retrieves the bundles' key.
   *
   * @return string
   *   Returns the bundles' key.
   */
  public function getBundlesKey(): string {

    return $this->bundlesKey;
  }

  /**
   * Retrieves the fieldname table.
   *
   * @return string
   *   Returns the fieldname table.
   */
  public function getFieldnameTable(): string {

    return $this->fieldnameTable;
  }

  /**
   * Retrieves the fieldname field.
   *
   * @return string
   *   Returns the fieldname field.
   */
  public function getFieldnameField(): string {

    return $this->fieldnameField;
  }

  /**
   * Retrieves the fieldname key.
   *
   * @return string
   *   Returns the fieldname key.
   */
  public function getFieldnameKey(): string {

    return $this->fieldnameKey;
  }

  /**
   * Retrieves the value table prefix.
   *
   * @return string
   *   Returns the value table prefix.
   */
  public function getValueTablePrefix(): string {

    return $this->valueTablePrefix;
  }

  /**
   * Retrieves the value field suffix.
   *
   * @return array
   *   Returns the value field suffix.
   */
  public function getValueFieldSuffix(): array {

    return $this->valueFieldSuffix;
  }

  /**
   * Retrieves the value key.
   *
   * @return string
   *   Returns the value key.
   */
  public function getValueKey(): string {

    return $this->valueKey;
  }

}
