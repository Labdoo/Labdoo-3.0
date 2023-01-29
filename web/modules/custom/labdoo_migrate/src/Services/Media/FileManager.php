<?php

namespace Drupal\labdoo_migrate\Services\Media;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Psr\Log\LoggerAwareTrait;
use Drupal\file\Entity\File;

/**
 * The file manager.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class FileManager implements FileManagerInterface {

  use LoggerAwareTrait;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private FileSystemInterface $fileSystem;

  /**
   * The file repository.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  private FileRepositoryInterface $fileRepository;

  /**
   * The configuration manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface
   */
  private ConfigurationManagerInterface $configurationManger;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  private AccountInterface $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * FileManager constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   The file repository.
   * @param \Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface $configurationManager
   *   The configuration manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    FileSystemInterface $fileSystem,
    FileRepositoryInterface $fileRepository,
    ConfigurationManagerInterface $configurationManager,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    AccountInterface $currentUser,
    EntityTypeManagerInterface $entityTypeManager
  ) {

    $this->fileSystem = $fileSystem;
    $this->fileRepository = $fileRepository;
    $this->configurationManger = $configurationManager;
    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $this->currentUser = $currentUser;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritDoc}
   */
  public function createFile(
    string $fileUri,
    string $fileName,
    string $fileContents
  ): ?EntityInterface {
    // Check if file already exists
    $existingFile = $this->fileExists($fileUri, $fileName);
    if ($existingFile !== NULL) {
      return $existingFile;
    }

    $filesConfig = $this->getFilesConfig();
    if (
      isset($filesConfig['copy_files_content'])
      && $filesConfig['copy_files_content'] === 'true'
    ) {
      try {
        $this->prepareDirectory();
      }
      catch (\Exception $e) {
        $this->logger->error($e->getMessage());

        return NULL;
      }
      $fileName = $this->buildFileName($fileName);
      $file = $this->fileRepository->writeData(
        $fileContents,
        $fileName,
        FileSystemInterface::EXISTS_REPLACE
      );
    }
    else {
      $file = $this->createOrUpdateFile(
        $fileUri,
        $fileUri,
        FALSE
      );
    }

    $file->setPermanent();
    if (!$file->save()) {
      $errorMessage = sprintf('Could not write the %s file', $fileName);
      $this->logger->error($errorMessage);
    }

    return $file;
  }

  /**
   * {@inheritDoc}
   */
  public function getFileContents(
    string $fileUri,
    bool $defaultContent = FALSE,
    bool $suppressErrors = FALSE
  ): string {

    $filesConfig = $this->getFilesConfig();
    $fileUri = str_replace(
      $filesConfig['replacement_token'],
      $filesConfig['source_files_folder'],
      $fileUri
    );

    if (!file_exists($fileUri)) {
      if (!$suppressErrors) {
        $errorMessage = sprintf('The file %s does not exist', $fileUri);
        $this->logger->error($errorMessage);
      }

      if ($defaultContent) {
        // Return a default 1x1 transparent PNG image as placeholder
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
      }

      return '';
    }

    return file_get_contents($fileUri);
  }

  /**
   * {@inheritDoc}
   */
  public function buildFileName(string $fileUri): string {

    // If no file name is provided, we need to generate a random one.
    if (!$fileUri) {
      $fileUri = uniqid(random_int(111111, 999999), TRUE);
    }
    $filesConfig = $this->getFilesConfig();
    $directory = $filesConfig['media_location'];
    $pathInfo = pathinfo($fileUri);

    return sprintf(
      '%s%s.%s',
      $directory,
      $pathInfo['filename'],
      $pathInfo['extension']
    );
  }

  /**
   * Prepares the directory.
   *
   * @return mixed
   *   Returns the directory name.
   *
   * @throws \Exception
   */
  protected function prepareDirectory() {

    $filesConfig = $this->getFilesConfig();
    $directory = $filesConfig['media_location'];
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      $errorMessage = sprintf('Could not create the %s directory', $directory);
      throw new \Exception($errorMessage);
    }

    return $directory;
  }

  /**
   * Retrieves the configuration of the files.
   *
   * @return array
   *   Returns the files configuration array.
   *
   * @throws \Exception
   */
  protected function getFilesConfig(): array {

    $globalConfig = $this->configurationManger->getGlobalConfiguration();

    return $globalConfig->getFilesConfig();
  }

  /**
   * Create a file entity or update if it exists.
   *
   * @param string $uri
   *   The file URI.
   * @param string $destination
   *   The destination URI.
   * @param bool $rename
   *   Whether to rename the file.
   *
   * @return \Drupal\file\Entity\File|\Drupal\file\FileInterface
   *   The file entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   Thrown when there is an error saving the file.
   */

  protected function createOrUpdateFile(
    string $uri,
    string $destination,
    bool $rename
  ) {

    $file = $this->fileRepository->loadByUri($uri);
    if ($file === NULL) {
      $file = File::create(['uri' => $uri]);
      $file->setOwnerId($this->currentUser->id());
    }

    if ($rename && is_file($destination)) {
      $file->setFilename($this->fileSystem->basename($destination));
    }

    $file->setPermanent();
    $file->save();

    return $file;
  }

  /**
   * {@inheritDoc}
   */
  public function fileExists(
    string $fileUri,
    string $fileName
  ): ?EntityInterface {
    try {
      // Try to load the file by URI first
      $file = $this->fileRepository->loadByUri($fileUri);
      if ($file !== NULL) {
        return $file;
      }

      // If not found by URI, try to find by both URI and filename
      $fileStorage = $this->entityTypeManager->getStorage('file');

      // First try with both URI and filename for exact match
      $files = $fileStorage->loadByProperties([
        'uri' => $fileUri,
        'filename' => $fileName,
      ]);

      if (!empty($files)) {
        return reset($files);
      }

      // If not found, try with just the filename
      $files = $fileStorage->loadByProperties([
        'filename' => $fileName,
      ]);

      if (!empty($files)) {
        return reset($files);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error checking if file exists: ' . $e->getMessage());
    }

    return NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function mediaEntityExists(
    string $bundle,
    string $name
  ): ?EntityInterface {
    try {
      // Try to find media entity by bundle and name
      $mediaStorage = $this->entityTypeManager->getStorage('media');
      $mediaEntities = $mediaStorage->loadByProperties([
        'bundle' => $bundle,
        'name' => $name,
      ]);

      if (!empty($mediaEntities)) {
        return reset($mediaEntities);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error checking if media entity exists: ' . $e->getMessage());
    }

    return NULL;
  }

}
