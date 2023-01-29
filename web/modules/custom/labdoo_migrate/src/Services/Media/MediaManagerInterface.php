<?php

namespace Drupal\labdoo_migrate\Services\Media;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for media managers.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface MediaManagerInterface {

  /**
   * Creates a media entity.
   *
   * @param string $fileUri
   *   The file URI.
   * @param string $fileName
   *   The file name.
   * @param string $fileContents
   *   The file contents.
   * @param array $metadata
   *   The metadata array.
   * @param string $langCode
   *   The language code.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Returns the created media.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function createMedia(
    string $fileUri,
    string $fileName,
    string $fileContents,
    array $metadata,
    string $langCode
  ): EntityInterface;

}
