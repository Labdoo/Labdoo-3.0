<?php

namespace Drupal\labdoo_migrate\Model;

/**
 * DTO for a taxonomy.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class TaxonomyModel {

  /**
   * The base table.
   *
   * @var string
   */
  private string $baseTable;

  /**
   * The key field.
   *
   * @var string
   */
  private string $keyField;

  /**
   * The value field.
   *
   * @var string
   */
  private string $valueField;

  /**
   * The language field.
   *
   * @var string
   */
  private string $langField;

  /**
   * The translation field.
   *
   * @var string
   */
  private string $translationField;

  /**
   * TaxonomyModel constructor.
   *
   * @param string $baseTable
   *   The base table.
   * @param string $keyField
   *   The key field.
   * @param string $valueField
   *   The value field.
   * @param string $langField
   *   The language field.
   * @param string $translationField
   *   The translation field.
   */
  public function __construct(
    string $baseTable,
    string $keyField,
    string $valueField,
    string $langField,
    string $translationField
  ) {

    $this->baseTable = $baseTable;
    $this->keyField = $keyField;
    $this->valueField = $valueField;
    $this->langField = $langField;
    $this->translationField = $translationField;
  }

  /**
   * Retrieves the base table.
   *
   * @return string
   *   Returns the base table.
   */
  public function getBaseTable(): string {

    return $this->baseTable;
  }

  /**
   * Retrieves the key field.
   *
   * @return string
   *   Returns the key field.
   */
  public function getKeyField(): string {

    return $this->keyField;
  }

  /**
   * Retrieves the value field.
   *
   * @return string
   *   Returns the value field.
   */
  public function getValueField(): string {

    return $this->valueField;
  }

  /**
   * Retrieves the language field.
   *
   * @return string
   *   Returns the language field.
   */
  public function getLanguageField(): string {

    return $this->langField;
  }

  /**
   * Retrieves the translation field.
   *
   * @return string
   *   Returns the translation field.
   */
  public function getTranslationField(): string {

    return $this->translationField;
  }

}
