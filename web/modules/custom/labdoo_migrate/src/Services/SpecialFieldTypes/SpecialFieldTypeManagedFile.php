<?php

namespace Drupal\labdoo_migrate\Services\SpecialFieldTypes;

use Drupal\Core\Entity\EntityInterface;
use Drupal\labdoo_migrate\Services\Media\FileManagerInterface;

/**
 * The special field type for files.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class SpecialFieldTypeManagedFile implements SpecialFieldTypeInterface {

  /**
   * The file manager.
   *
   * @var \Drupal\labdoo_migrate\Services\Media\FileManagerInterface
   */
  private FileManagerInterface $fileManager;

  /**
   * SpecialFieldTypeFile constructor.
   *
   * @param \Drupal\labdoo_migrate\Services\Media\FileManagerInterface $fileManager
   *   The file manager.
   */
  public function __construct(
    FileManagerInterface $fileManager,
  ) {

    $this->fileManager = $fileManager;
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
    $fileEntity = $this->fileManager->createFile(
      $value,
      $fileName,
      $fileContents
    );
    if ($fileEntity === NULL) {
      $errorMessage = sprintf(
        'Failed creating media file with URI %s',
        $value
      );
      throw new \Exception($errorMessage);
    }

    return ['target_id' => $fileEntity->id()];
  }

  /**
   * {@inheritDoc}
   */
  public function filterValue($value) {

    return $value;
  }

}
