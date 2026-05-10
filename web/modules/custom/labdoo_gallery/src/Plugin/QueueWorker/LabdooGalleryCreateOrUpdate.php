<?php

namespace Drupal\labdoo_gallery\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\labdoo_gallery\Service\Compute\GalleryComputeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates or updates a gallery entity.
 *
 * @QueueWorker(
 *   id = "labdoo_gallery_create_or_update",
 *   title = @Translation("Labdoo Gallery Create or Update"),
 *   cron = {"time" = 60}
 * )
 */
class LabdooGalleryCreateOrUpdate extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The gallery compute service.
   *
   * @var \Drupal\labdoo_gallery\Service\Compute\GalleryComputeInterface
   */
  protected GalleryComputeInterface $galleryCompute;

  /**
   * Constructs a new LabdooGalleryCreateOrUpdate object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\labdoo_gallery\Service\Compute\GalleryComputeInterface $gallery_compute
   *   The gallery compute service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, GalleryComputeInterface $gallery_compute) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->galleryCompute = $gallery_compute;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('labdoo_gallery.compute')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $entity_id = $data['entity_id'];
    $entity_type = $data['entity_type'];
    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);

    if ($entity) {
      $this->galleryCompute->createOrUpdateGallery($entity);
    }
  }

}
