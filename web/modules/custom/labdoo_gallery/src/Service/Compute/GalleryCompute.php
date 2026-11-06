<?php

namespace Drupal\labdoo_gallery\Service\Compute;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Database\Connection;
use Drupal\labdoo_gallery\Services\LabdooGalleryRepositoryInterface;

/**
 * Service to compute Gallery data.
 */
class GalleryCompute implements GalleryComputeInterface {

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The gallery repository.
   *
   * @var \Drupal\labdoo_gallery\Services\LabdooGalleryRepositoryInterface
   */
  protected LabdooGalleryRepositoryInterface $galleryRepository;

  /**
   * GalleryCompute constructor.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\labdoo_gallery\Services\LabdooGalleryRepositoryInterface $gallery_repository
   *   The gallery repository.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(QueueFactory $queue_factory, LabdooGalleryRepositoryInterface $gallery_repository, Connection $database) {
    $this->queueFactory = $queue_factory;
    $this->galleryRepository = $gallery_repository;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public function enqueueGalleryCreateOrUpdate(EntityInterface $entity): void {
    // Only enqueue if it's a new entity or the label has changed.
    if (
      !$entity->isNew()
      && isset($entity->original)
      && $entity->label() === $entity->original->label()
    ) {
      return;
    }

    $queue = $this->queueFactory->get('labdoo_gallery_create_or_update');
    $item = [
      'entity_id' => $entity->id(),
      'entity_type' => $entity->getEntityTypeId(),
    ];
    $queue->createItem($item);
  }

  /**
   * {@inheritdoc}
   */
  public function createOrUpdateGallery(EntityInterface $entity): void {
    if (
      $entity->getEntityTypeId() !== 'node'
      || !in_array($entity->bundle(), ['edoovillage', 'hub'], TRUE)
    ) {
      return;
    }
    // Serialize album creation with deletion of its parent row. The lock lasts
    // until this transaction finishes, including the gallery save below.
    $transaction = $this->database->startTransaction();
    $parent = $this->database->select('node', 'parent')
      ->fields('parent', ['nid'])
      ->condition('nid', $entity->id())
      ->forUpdate()->execute()->fetchField();
    if (!$parent) {
      unset($transaction);
      return;
    }
    $gallery = $this->galleryRepository->loadByParentId((int) $entity->id());
    $label = $entity->label();
    $suffix = ' - Photo Album';
    $title = $label . $suffix;
    if (mb_strlen($title) > 255) {
      $label = mb_substr($label, 0, 255 - mb_strlen($suffix) - 3) . '...';
      $title = $label . $suffix;
    }

    $data = [
      'title' => $title,
      'field_parent' => (int) $entity->id(),
    ];
    if (empty($gallery)) {
      $this->galleryRepository->create($data);
    }
    else {
      $this->galleryRepository->update($gallery->id(), $data);
    }
    unset($transaction);
  }

}
