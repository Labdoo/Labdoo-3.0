<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;
use Drupal\labdoo_migrate\Services\Media\FileManagerInterface;
use Drupal\labdoo_migrate\Services\Media\MediaManagerInterface;

/**
 * The special field type for files.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypeFile implements SpecialFieldTypeInterface {

  /**
   * The file manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Media\FileManagerInterface
   */
  private FileManagerInterface $fileManager;

  /**
   * The media manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Media\MediaManagerInterface
   */
  private MediaManagerInterface $mediaManager;

  /**
   * SpecialFieldTypeFile constructor.
   *
   * @param \Drupal\labdoo_migrate\Services\Media\FileManagerInterface $fileManager
   *   The file manager.
   * @param \Drupal\labdoo_migrate\Services\Media\MediaManagerInterface $mediaManager
   *   The media manager.
   */
  public function __construct(
    FileManagerInterface $fileManager,
    MediaManagerInterface $mediaManager
  ) {

    $this->fileManager = $fileManager;
    $this->mediaManager = $mediaManager;
  }

  /**
   * {@inheritDoc}
   */
  public function getValue(
    $value,
    array $metadata,
    EntityInterface $entity,
    string $mainLangCode
  ) {

    if (!is_array($value)) {
      return $this->processSingleFile($value, $metadata, $entity);
    }

    $result = [];
    foreach ($value as $singleValue) {
      $result[] = $this->processSingleFile($singleValue, $metadata, $entity);
    }

    return $result;
  }

  /**
   * Processes a single file.
   *
   * @param mixed $value
   *   The value.
   * @param array $metadata
   *   The metadata array.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return array
   *   Returns an array with the file entity.
   *
   * @throws \Exception
   */
  protected function processSingleFile($value, array $metadata, EntityInterface $entity): array {

    $fileContents = $this->fileManager->getFileContents($value);
    $fileName = $this->fileManager->buildFileName($value);
    $mediaEntity = $this->mediaManager->createMedia(
      $value,
      $fileName,
      $fileContents,
      $metadata,
      $entity->language()->getId()
    );

    return ['target_id' => $mediaEntity->id()];
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
