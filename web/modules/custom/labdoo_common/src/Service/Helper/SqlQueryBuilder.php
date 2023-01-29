<?php

namespace Drupal\labdoo_common\Service\Helper;

use Drupal\Core\Database\Query\SelectInterface;

/**
 * Provides a method to build raw SQL from a SelectInterface object.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SqlQueryBuilder {

  /**
   * Builds the raw SQL string from the given SelectInterface.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $select
   *   The SelectInterface object to process.
   *
   * @return string
   *   The resulting SQL query string.
   */
  public static function buildRawSql(SelectInterface $select): string {
    // 1) Get the SQL string representation from the SelectInterface.
    // For example: "SELECT t.field FROM {table} t WHERE t.id = :id".
    $sql = $select->__toString();

    // 2) Replace placeholders with their corresponding values in the query.
    // If you have array placeholders or multiple placeholders representing
    // one condition, you need to handle them individually.
    $args = $select->arguments();
    $args = self::sortPlaceholdersDescending($args);
    foreach ($args as $placeholder => $value) {
      // Convert string values to properly escaped versions.
      // If $value is an array, you might need additional parsing logic.
      if (is_string($value)) {
        $value = "'" . addslashes($value) . "'";
      }
      elseif (is_numeric($value)) {
        // Cast numeric values to int or float as needed.
        $value = (string) $value;
      }
      elseif (is_null($value)) {
        $value = 'NULL';
      }
      // Replace the ":placeholder" in the SQL string with the actual value.
      $sql = str_replace($placeholder, $value, $sql);
    }

    // 3) Remove braces that Drupal uses for table names.
    // For example "{table}" becomes "table".
    $sql = str_replace(['{', '}'], '', $sql);

    // 4) Remove double quotes that Drupal might apply (e.g., "cp", "cpfd").
    // Be cautious: removing double quotes can break queries if your DB
    // needs them for case-sensitive identifiers or reserved words.
    $sql = str_replace('"', '', $sql);

    return $sql;
  }

  /**
   * Sorts an array of placeholders in descending numeric order.
   *
   * @param array $placeholders
   *   An array of placeholders in the format ':db_condition_placeholder_<num>'.
   *
   * @return array
   *   The sorted array of placeholders, ordered by descending numeric value.
   */
  protected static function sortPlaceholdersDescending(array $placeholders): array {
    // 1) Extract the keys into an array.
    $keys = array_keys($placeholders);

    // 2) Sort those keys by extracting the numeric part.
    usort($keys, function ($a, $b) {
      // Take the ending number of ':db_condition_placeholder_XX'.
      $numA = (int) preg_replace('/^:db_condition_placeholder_/', '', $a);
      $numB = (int) preg_replace('/^:db_condition_placeholder_/', '', $b);

      // Descending order (from largest to smallest).
      return $numB <=> $numA;
    });

    // 3) Rebuild the array with the reordered keys.
    $sortedArray = [];
    foreach ($keys as $key) {
      $sortedArray[$key] = $placeholders[$key];
    }

    return $sortedArray;
  }

}
