<?php

namespace Drupal\labdoo_migrate\Services\Media;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for file managers.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
interface FileManagerInterface {

  /**
   * Creates a file.
   *
   * @param string $fileUri
   *   The file URI.
   * @param string $fileName
   *   The file name.
   * @param string $fileContents
   *   The file contents.
   *
   * @return null|\Drupal\Core\Entity\EntityInterface
   *   Returns the created media.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Exception
   */
  public function createFile(
    string $fileUri,
    string $fileName,
    string $fileContents
  ): ?EntityInterface;

  /**
   * Retrieves the file contents.
   *
   * @param string $fileUri
   *   The file URI.
   * @param bool $defaultContent
   *   If TRUE, and the file doesn't exist, a default content will be returned.
   * @param bool $suppressErrors
   *   If TRUE, errors will be suppressed.
   *
   * @return string
   *   Returns the file contents.
   *
   * @throws \Exception
   */
  public function getFileContents(
    string $fileUri,
    bool $defaultContent = FALSE,
    bool $suppressErrors = FALSE
  ): string;

  /**
   * Builds the file name.
   *
   * @param string $fileUri
   *   The file URI.
   *
   * @return string
   *   Returns the file name.
   *
   * @throws \Exception
   */
  public function buildFileName(string $fileUri): string;

  /**
   * Checks if a file exists.
   *
   * @param string $fileUri
   *   The file URI.
   * @param string $fileName
   *   The file name.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   Returns the file entity if it exists, NULL otherwise.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function fileExists(
    string $fileUri,
    string $fileName
  ): ?EntityInterface;

  /**
   * Checks if a media entity exists.
   *
   * @param string $bundle
   *   The media bundle.
   * @param string $name
   *   The media name.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   Returns the media entity if it exists, NULL otherwise.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function mediaEntityExists(
    string $bundle,
    string $name
  ): ?EntityInterface;

  }
