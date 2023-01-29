<?php

namespace Drupal\labdoo_migrate\Services\Media;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The media manager.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MediaManager implements MediaManagerInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Media\FileManagerInterface
   */
  private FileManagerInterface $fileManager;

  /**
   * MediaManager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\labdoo_migrate\Services\Media\FileManagerInterface $fileManager
   *   The file manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FileManagerInterface $fileManager
  ) {

    $this->entityTypeManager = $entityTypeManager;
    $this->fileManager = $fileManager;
  }

  /**
   * {@inheritDoc}
   */
  public function createMedia(
    string $fileUri,
    string $fileName,
    string $fileContents,
    array $metadata,
    string $langCode
  ): EntityInterface {

    $file = $this->fileManager->createFile(
      $fileUri,
      $fileName,
      $fileContents
    );
    if ($file === NULL) {
      $errorMessage = sprintf(
        'Failed creating media file with URI %s',
        $fileUri
      );
      throw new \Exception($errorMessage);
    }

    $entity = $this->entityTypeManager
      ->getStorage('media')
      ->create([
        'bundle' => $metadata['bundle'],
        'status' => TRUE,
        'langcode' => $langCode,
        $metadata['destination_field'] => [
          'target_id' => $file->id(),
        ],
      ]);
    $entity->setPublished()->save();

    return $entity;
  }

}
