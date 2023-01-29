<?php

namespace Drupal\labdoo_migrate\Model;

/**
 * DTO for Join data.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class JoinModel {

  /**
   * The table name.
   *
   * @var string
   */
  private string $table;

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
   * JoinModel constructor.
   *
   * @param string $table
   *   The table.
   * @param string $keyField
   *   The key field.
   * @param string $valueField
   *   The value field.
   */
  public function __construct(
    string $table,
    string $keyField,
    string $valueField
  ) {

    $this->table = $table;
    $this->keyField = $keyField;
    $this->valueField = $valueField;
  }

  /**
   * Retrieves the table.
   *
   * @return string
   *   Returns the table.
   */
  public function getTable(): string {

    return $this->table;
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

}
