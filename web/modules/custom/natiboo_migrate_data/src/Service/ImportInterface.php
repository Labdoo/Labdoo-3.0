<?php

namespace Drupal\natiboo_migrate_data\Service;

/**
 * Defines the interface for importing menu and menu links data.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface ImportInterface {

  /**
   * Retrieves and decodes JSON data from an uploaded file.
   *
   * @return array
   *   Decoded JSON data containing "menu" and "links".
   *
   * @throws \Exception
   *   Thrown when no file is uploaded, the file is empty,
   *   the file cannot be read, or the decoded content does not contain
   *   the required "menu" and "links" keys.
   */
  public function getFileData(): array;

  /**
   * Imports menu and menu links data.
   *
   * @param array $data
   *   An associative array containing 'menu' and 'links' data to be imported.
   *   - 'menu': An array with menu details such as id, label, description,
   *     langcode, and status.
   *   - 'links': An array of menu link data, each containing properties such as
   *     UUID and other menu link details.
   * @param bool $overwrite
   *   (optional) A boolean indicating whether to overwrite existing menu links
   *   if their UUIDs match. Defaults to FALSE.
   *
   * @return void
   *   No return value.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function import(array $data, bool $overwrite = FALSE): void;

}
