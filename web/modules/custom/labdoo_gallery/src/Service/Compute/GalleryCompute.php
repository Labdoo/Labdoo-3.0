<?php

namespace Drupal\labdoo_gallery\Service\Compute;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Queue\QueueFactory;
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
   */
  public function __construct(QueueFactory $queue_factory, LabdooGalleryRepositoryInterface $gallery_repository) {
    $this->queueFactory = $queue_factory;
    $this->galleryRepository = $gallery_repository;
  }

  /**
   * {@inheritdoc}
   */
  public function enqueueGalleryCreateOrUpdate(EntityInterface $entity): void {
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
  }

}
