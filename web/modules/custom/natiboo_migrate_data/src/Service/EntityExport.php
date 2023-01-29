<?php

namespace Drupal\natiboo_migrate_data\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Service to export Drupal entities to JSON.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class EntityExport {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a new instance of the class.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service for handling entity operations.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerChannelFactory,
  ) {
    $this->logger = $loggerChannelFactory->get('natiboo_migrate_data');
  }

  /**
   * Exports an entity to a JSON-compatible array representation.
   *
   * @param string $entityType
   *   The type of the entity to export (e.g., "node" or "user").
   * @param int $entityId
   *   The ID of the entity to export.
   *
   * @return array
   *   An associative array representing the serialized data of the entity,
   *   ready for JSON encoding.
   *
   * @throws \Exception
   *   Thrown if the entity of the specified type and ID cannot be found.
   */
  public function export(
    string $entityType,
    int $entityId,
  ): array {
    $entity = $this->entityTypeManager->getStorage($entityType)
      ->load($entityId);
    if (!$entity) {
      throw new \Exception("Entity of type $entityType with ID $entityId not found.");
    }

    return $this->serializeEntity($entity);
  }

  /**
   * Serializes a given entity into an array representation.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be serialized.
   *
   * @return array
   *   An associative array containing the serialized data of the entity,
   *   including the entity type, bundle, ID, UUID, and accessible fields.
   */
  protected function serializeEntity(EntityInterface $entity): array {
    $data = [
      'entity_type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'id' => $entity->id(),
      'uuid' => $entity->uuid(),
      'fields' => [],
    ];

    foreach ($entity->getFields() as $fieldName => $field) {
      if ($field->access('view')) {
        $field_type = $field->getFieldDefinition()->getType();
        $data['fields'][$fieldName] = $this->serializeField($field, $field_type);
      }
    }

    return $data;
  }

  /**
   * Serializes a field into an array representation based on its type.
   *
   * @param mixed $field
   *   The field to be serialized.
   * @param string $fieldType
   *   The type of the field (e.g., image, file, entity_reference, etc.).
   *
   * @return array
   *   An associative array containing the serialized data for the field. The
   *   structure and data of the returned array vary depending on the field type
   *   and its contents.
   */
  protected function serializeField($field, string $fieldType): array {
    $values = [];
    foreach ($field as $item) {
      if ($fieldType === 'image' || $fieldType === 'file') {
        try {
          $file = $this->entityTypeManager
            ->getStorage('file')
            ->load($item->target_id);
        }
        catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
          $this->logger->error($e->getMessage());

          continue;
        }

        if (!$file) {
          continue;
        }

        $fileUri = $file->getFileUri();
        if ($fileUri === NULL) {
          continue;
        }

        $file_data = (string) file_get_contents($fileUri);
        $values[] = [
          'target_id' => $item->target_id,
          'filename' => $file->getFilename(),
          'mime_type' => $file->getMimeType(),
          'data' => base64_encode($file_data),
          'alt' => $item->alt ?? '',
          'title' => $item->title ?? '',
        ];
      }
      elseif ($item->entity instanceof Paragraph) {
        $values[] = $this->serializeEntity($item->entity);
      }
      elseif (
        $fieldType === 'entity_reference'
        && $item->entity->getEntityTypeId() === 'media'
      ) {
        try {
          $media = $item->entity;
          $mediaData = [
            'target_id' => $media->id(),
            'uuid' => $media->uuid(),
            'bundle' => $media->bundle(),
          ];

          $sourceField = $this->getMediaSourceFieldName($media);
          if ($sourceField && $media->hasField($sourceField)) {
            $fileItem = $media->get($sourceField)->first();
            if ($fileItem && $fileItem->entity) {
              $file = $fileItem->entity;
              $fileUri = $file->getFileUri();
              $fileData = (string) file_get_contents($fileUri);

              $mediaData['file'] = [
                'filename' => $file->getFilename(),
                'mime_type' => $file->getMimeType(),
                'data' => base64_encode($fileData),
              ];
            }
          }

          $values[] = $mediaData;
        }
        catch (\Exception $e) {
          $this->logger->error('Error processing media entity: @message', ['@message' => $e->getMessage()]);
          continue;
        }
      }
      elseif ($fieldType === 'entity_reference') {
        $values[] = [
          'target_id' => $item->target_id,
          'entity_type' => $item->target_type,
        ];
      }
      else {
        $values[] = $item->value;
      }
    }

    return $values;
  }

  /**
   * Get the source field name of a media entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $media
   *   The media entity.
   *
   * @return string|null
   *   The source field name, or NULL if not found.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getMediaSourceFieldName(EntityInterface $media): ?string {
    $bundle = $media->bundle();
    $fieldDefinitions = $this->entityTypeManager
      ->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'media',
        'bundle' => $bundle,
      ]);

    foreach ($fieldDefinitions as $fieldDefinition) {
      if (
        $fieldDefinition->getType() === 'file'
        || $fieldDefinition->getType() === 'image'
      ) {
        return $fieldDefinition->getName();
      }
    }

    return NULL;
  }

}
