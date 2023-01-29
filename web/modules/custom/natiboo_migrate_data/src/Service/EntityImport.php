<?php

namespace Drupal\natiboo_migrate_data\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Service to import Drupal entities from JSON.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class EntityImport implements ImportInterface {

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
   *   The entity type manager service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   The file repository service.
   * @param \Drupal\natiboo_migrate_data\Service\ImportHelper $importHelper
   *   The import helper.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    protected ImportHelper $importHelper,
    LoggerChannelFactoryInterface $loggerChannelFactory,
  ) {
    $this->logger = $loggerChannelFactory->get('natiboo_migrate_data');
  }

  /**
   * {@inheritDoc}
   */
  public function getFileData(): array {
    $data = $this->importHelper->getFileData();
    if (
      !isset($data['entity_type'])
      || !isset($data['bundle'])
      || !isset($data['fields'])
    ) {
      throw new \Exception("Invalid JSON structure: Missing required keys.");
    }

    return $data;
  }

  /**
   * {@inheritDoc}
   */
  public function import(array $data, bool $overwrite = FALSE): void {
    $entityType = $data['entity_type'];
    $uuid = $data['fields']['uuid'] ?? NULL;

    // If $uuid is an array, extract the first value or use NULL
    if (is_array($uuid) && !empty($uuid)) {
      $this->logger->notice(
        'UUID field is an array, using the first value: @uuid',
        ['@uuid' => print_r($uuid, TRUE)]
      );
      $uuid = reset($uuid);
      // If the first value is an array too, we can't use it as a UUID
      if (is_array($uuid)) {
        $this->logger->warning(
          'UUID field contains nested arrays, cannot use as entity UUID',
          []
        );
        $uuid = NULL;
      }
    }

    if ($uuid) {
      $storage = $this->entityTypeManager->getStorage($entityType);
      $existingEntities = $storage->loadByProperties(['uuid' => $uuid]);

      if (!empty($existingEntities)) {
        $existingEntity = reset($existingEntities);
        if ($overwrite) {
          // Update the existing entity with new field values.
          foreach ($data['fields'] as $fieldName => $fieldValues) {
            // Skip the UUID field to avoid conflicts
            if ($fieldName === 'uuid') {
              $this->logger->notice(
                'Skipping UUID field during update to avoid conflicts',
                []
              );
              continue;
            }

            $fieldDefinition = $existingEntity->getFieldDefinition($fieldName);
            if ($fieldDefinition) {
              $fieldType = $fieldDefinition->getType();
              $existingEntity->set(
                $fieldName,
                $this->deserializeField($fieldValues, $fieldType)
              );
            }
          }
          $existingEntity->save();
        }
      }
    }

    // Create a new entity if none exists or UUID is not provided.
    $this->createEntity($data);
  }

  /**
   * Creates a new entity based on the provided data.
   *
   * @param array $data
   *   An associative array containing the entity data:
   *   - entity_type: The machine name of the entity type to create.
   *   - bundle: The bundle (subtype) of the entity to create.
   *   - fields: An associative array of fields.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The created and saved entity object.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createEntity(array $data): EntityInterface {
    $entityType = $data['entity_type'];
    $bundle = $data['bundle'];
    $values = ['type' => $bundle];

    // Check if the data contains an ID that might conflict with existing entities
    $id = $data['fields']['id'] ?? NULL;
    // If $id is an array, extract the first value or use NULL
    if (is_array($id) && !empty($id)) {
      $this->logger->notice(
        'ID field is an array, using the first value: @id',
        ['@id' => print_r($id, TRUE)]
      );
      $id = reset($id);
      // If the first value is an array too, we can't use it as an ID
      if (is_array($id)) {
        $this->logger->warning(
          'ID field contains nested arrays, cannot use as entity ID',
          []
        );
        $id = NULL;
      }
    }

    // Check if the data contains a revision_id that might conflict with existing entities
    $revision_id = $data['fields']['revision_id'] ?? NULL;
    // If $revision_id is an array, extract the first value or use NULL
    if (is_array($revision_id) && !empty($revision_id)) {
      $this->logger->notice(
        'Revision ID field is an array, using the first value: @revision_id',
        ['@revision_id' => print_r($revision_id, TRUE)]
      );
      $revision_id = reset($revision_id);
      // If the first value is an array too, we can't use it as a revision ID
      if (is_array($revision_id)) {
        $this->logger->warning(
          'Revision ID field contains nested arrays, cannot use as entity revision ID',
          []
        );
        $revision_id = NULL;
      }
    }

    // Check if the data contains a UUID that might conflict with existing entities
    $uuid = $data['fields']['uuid'] ?? NULL;
    // If $uuid is an array, extract the first value or use NULL
    if (is_array($uuid) && !empty($uuid)) {
      $this->logger->notice(
        'UUID field is an array, using the first value: @uuid',
        ['@uuid' => print_r($uuid, TRUE)]
      );
      $uuid = reset($uuid);
      // If the first value is an array too, we can't use it as a UUID
      if (is_array($uuid)) {
        $this->logger->warning(
          'UUID field contains nested arrays, cannot use as entity UUID',
          []
        );
        $uuid = NULL;
      }
    }

    if ($id && $entityType === 'paragraph') {
      // Check if a paragraph with this ID already exists
      $existingEntity = $this->entityTypeManager
        ->getStorage($entityType)
        ->load($id);

      if ($existingEntity) {
        // If entity with this ID already exists, don't use the ID from the data
        // This will allow Drupal to auto-generate a new ID
        $this->logger->notice(
          'Entity of type @type with ID @id already exists. A new ID will be generated.',
          ['@type' => $entityType, '@id' => $id]
        );
        unset($data['fields']['id']);
      } else {
        // If the ID doesn't conflict, set it explicitly in the values
        $values['id'] = $id;
      }
    }

    // Check if a paragraph with this revision ID already exists
    if ($revision_id && $entityType === 'paragraph') {
      $query = $this->entityTypeManager->getStorage($entityType)->getQuery()->accessCheck();
      $query->condition('revision_id', $revision_id);
      $result = $query->execute();

      if (!empty($result)) {
        // If entity with this revision ID already exists, don't use the revision_id from the data
        // This will allow Drupal to auto-generate a new revision ID
        $this->logger->notice(
          'Entity of type @type with revision ID @revision_id already exists. A new revision ID will be generated.',
          ['@type' => $entityType, '@revision_id' => $revision_id]
        );
        unset($data['fields']['revision_id']);
      } else {
        // If the revision ID doesn't conflict, set it explicitly in the values
        $values['revision_id'] = $revision_id;
      }
    }

    // Check if an entity with this UUID already exists
    if ($uuid && $entityType === 'paragraph') {
      $existingEntities = $this->entityTypeManager
        ->getStorage($entityType)
        ->loadByProperties(['uuid' => $uuid]);

      if (!empty($existingEntities)) {
        // If entity with this UUID already exists, don't use the UUID from the data
        // This will allow Drupal to generate a new UUID
        $this->logger->notice(
          'Entity of type @type with UUID @uuid already exists. A new UUID will be generated.',
          ['@type' => $entityType, '@uuid' => $uuid]
        );
        unset($data['fields']['uuid']);
      } else {
        // If the UUID doesn't conflict, set it explicitly in the values
        $values['uuid'] = $uuid;
      }
    }

    if ($entityType === 'paragraph') {
      $entity = Paragraph::create($values);
    }
    else {
      $entity = $this->entityTypeManager
        ->getStorage($entityType)
        ->create($values);
    }

    foreach ($data['fields'] as $fieldName => $fieldValues) {
      // Skip the ID, revision_id, and uuid fields as they're handled separately
      if ($fieldName === 'id' || $fieldName === 'revision_id' || $fieldName === 'uuid') {
        continue;
      }

      $fieldDefinition = $entity->getFieldDefinition($fieldName);
      if (!$fieldDefinition) {
        continue;
      }
      $fieldType = $fieldDefinition->getType();
      $entity->set(
        $fieldName,
        $this->deserializeField($fieldValues, $fieldType)
      );
    }

    $entity->save();

    return $entity;
  }

  /**
   * Deserializes field values into the required format based on the field type.
   *
   * @param array $fieldValues
   *   The values of the field that need to be deserialized.
   *   This may include data such as file information, entity references,
   *   or basic values.
   * @param string $fieldType
   *   The type of the field. Supported types include 'image', 'file',
   *   and 'entity_reference'.
   *   Other types are preserved as-is.
   *
   * @return array
   *   The deserialized field values, formatted according
   *   to the specified field type.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function deserializeField(
    array $fieldValues,
    string $fieldType,
  ): array {
    $values = [];

    foreach ($fieldValues as $item) {
      if ($fieldType === 'image' || $fieldType === 'file') {
        $fileData = base64_decode($item['data']);
        $destination = 'public://imported/' . $item['filename'];
        $directory = 'public://imported';
        $this->fileSystem->prepareDirectory(
          $directory,
          FileSystemInterface::CREATE_DIRECTORY
        );
        $file = $this->fileRepository->writeData(
          $fileData,
          $destination,
          FileExists::Replace
        );
        $values[] = [
          'target_id' => $file->id(),
          'alt' => $item['alt'] ?? '',
          'title' => $item['title'] ?? '',
        ];
      }
      elseif (
        isset($item['entity_type'])
        && $item['entity_type'] === 'media'
      ) {
        try {
          $mediaValues = [
            'bundle' => $item['bundle'],
            'name' => "Imported media - {$item['uuid']}",
          ];

          $sourceField = $this->getMediaSourceFieldName($item['bundle']);
          if ($sourceField && isset($item['file'])) {
            $fileData = base64_decode($item['file']['data']);
            $destination = 'public://imported/' . $item['file']['filename'];
            $directory = 'public://imported';
            $this->fileSystem->prepareDirectory(
              $directory,
              FileSystemInterface::CREATE_DIRECTORY
            );

            $file = $this->fileRepository->writeData(
              $fileData,
              $destination,
              FileExists::Replace
            );

            $mediaValues[$sourceField] = [
              [
                'target_id' => $file->id(),
              ],
            ];
          }

          $media = $this->entityTypeManager
            ->getStorage('media')
            ->create($mediaValues);
          $media->save();

          $values[] = ['target_id' => $media->id()];
        }
        catch (\Exception $e) {
          $this->logger->error(
            'Error processing media entity: @message',
            ['@message' => $e->getMessage()]
          );

          continue;
        }
      }
      elseif (
        isset($item['entity_type'])
        && $item['entity_type'] === 'paragraph'
      ) {
        $uuid = $item['uuid'] ?? NULL;
        // If $uuid is an array, extract the first value or use NULL
        if (is_array($uuid) && !empty($uuid)) {
          $this->logger->notice(
            'Paragraph UUID is an array, using the first value: @uuid',
            ['@uuid' => print_r($uuid, TRUE)]
          );
          $uuid = reset($uuid);
          // If the first value is an array too, we can't use it as a UUID
          if (is_array($uuid)) {
            $this->logger->warning(
              'Paragraph UUID contains nested arrays, cannot use as entity UUID',
              []
            );
            $uuid = NULL;
          }
        }

        if ($uuid) {
          $existingParagraphs = $this->entityTypeManager
            ->getStorage('paragraph')
            ->loadByProperties(['uuid' => $uuid]);

          if (!empty($existingParagraphs)) {
            $paragraph = reset($existingParagraphs);
            foreach ($item['fields'] as $fieldName => $fieldValues) {
              // Skip the UUID field to avoid conflicts
              if ($fieldName === 'uuid') {
                $this->logger->notice(
                  'Skipping UUID field during paragraph update to avoid conflicts',
                  []
                );
                continue;
              }

              $fieldDefinition = $paragraph->getFieldDefinition($fieldName);
              if ($fieldDefinition) {
                $fieldType = $fieldDefinition->getType();
                $paragraph->set(
                  $fieldName,
                  $this->deserializeField($fieldValues, $fieldType)
                );
              }
            }
            $paragraph->save();
          }
          else {
            $paragraph = $this->createEntity($item);
          }

          $values[] = [
            'target_id' => $paragraph->id(),
            'target_revision_id' => $paragraph->getRevisionId(),
          ];
        }
        else {
          $this->logger->error('El dato de párrafo no contiene un UUID válido. No se puede procesar.');
        }
      }
      elseif ($fieldType === 'entity_reference') {
        $target_id = $item['target_id'] ?? NULL;
        // Handle case where target_id is an array
        if (is_array($target_id) && !empty($target_id)) {
          $this->logger->notice(
            'Target ID is an array, using the first value: @id',
            ['@id' => print_r($target_id, TRUE)]
          );
          $target_id = reset($target_id);
          // If the first value is an array too, we can't use it as a target_id
          if (is_array($target_id)) {
            $this->logger->warning(
              'Target ID contains nested arrays, cannot use as entity reference',
              []
            );
            $target_id = NULL;
          }
        }

        if ($target_id !== NULL) {
          $values[] = ['target_id' => $target_id];
        }
      }
      else {
        $values[] = $item;
      }
    }

    return $values;
  }

  /**
   * Gets the source field name for a media bundle.
   *
   * @param string $mediaBundle
   *   The media bundle machine name.
   *
   * @return string|null
   *   The source field name if found, or NULL otherwise.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getMediaSourceFieldName(string $mediaBundle): ?string {
    $fieldDefinitions = $this->entityTypeManager
      ->getStorage('field_config')
      ->loadByProperties([
        'entity_type' => 'media',
        'bundle' => $mediaBundle,
      ]);

    foreach ($fieldDefinitions as $fieldDefinition) {
      if (in_array($fieldDefinition->getType(), ['file', 'image'])) {
        return $fieldDefinition->getName();
      }
    }

    return NULL;
  }

}
