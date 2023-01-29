<?php

namespace Drupal\labdoo_gallery\Services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * The Labdoo Gallery repository service.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class LabdooGalleryRepository implements LabdooGalleryRepositoryInterface {

  use LoggerAwareTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The entity type for galleries.
   *
   * @var string
   */
  protected string $entityType = 'node';

  /**
   * The bundle for galleries.
   *
   * @var string
   */
  protected string $bundle = 'gallery';

  /**
   * Constructs a new LabdooGalleryRepository object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->logger = $loggerFactory->get('labdoo_gallery');
  }

  /**
   * {@inheritdoc}
   */
  public function loadById(int $id): ?EntityInterface {
    try {
      $storage = $this->entityTypeManager->getStorage($this->entityType);
      $entity = $storage->load($id);
      
      if ($entity && $entity->bundle() === $this->bundle) {
        return $entity;
      }
      
      return NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading gallery by ID: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadByParentId(int $parentId): ?EntityInterface {
    try {
      $entities = $this->entityTypeManager
        ->getStorage($this->entityType)
        ->loadByProperties([
          'type' => $this->bundle,
          'field_parent' => $parentId,
        ]);

      if (empty($entities)) {
        return NULL;
      }
      
      return reset($entities);
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading galleries by parent ID: @message', ['@message' => $e->getMessage()]);

      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function create(array $data): ?EntityInterface {
    try {
      $storage = $this->entityTypeManager->getStorage($this->entityType);
      
      // Set default values for required fields if not provided.
      $data['type'] = $this->bundle;
      
      if (!isset($data['title'])) {
        $data['title'] = 'Gallery ' . time();
      }
      
      $entity = $storage->create($data);
      $entity->save();
      
      return $entity;
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating gallery: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function update(int $id, array $data): ?EntityInterface {
    try {
      $entity = $this->loadById($id);
      
      if (!$entity) {
        $this->logger->warning('Gallery with ID @id not found for update.', ['@id' => $id]);
        return NULL;
      }
      
      foreach ($data as $field => $value) {
        if ($entity->hasField($field)) {
          $entity->set($field, $value);
        }
      }
      
      $entity->save();
      
      return $entity;
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating gallery: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

}