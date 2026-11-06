<?php

namespace Drupal\labdoo_gallery\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays media thumbnails in pages of 25 images.
 *
 * @FieldFormatter(
 *   id = "labdoo_gallery_grid",
 *   label = @Translation("Gallery thumbnail grid"),
 *   field_types = {"entity_reference"}
 * )
 */
class GalleryGrid extends EntityReferenceFormatterBase {

  /**
   * The media view builder provider.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected PagerManagerInterface $pagerManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->pagerManager = $container->get('pager.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $media = array_values($this->getEntitiesToView($items, $langcode));
    if (!$media) {
      return [];
    }
    // Keep the gallery pager separate from sidebar Views pagers.
    $pager = $this->pagerManager->createPager(count($media), 25, 2);
    $grid = [
      '#type' => 'container',
      '#attributes' => ['class' => ['labdoo-gallery-grid']],
    ];
    $builder = $this->entityTypeManager->getViewBuilder('media');
    foreach (array_slice($media, $pager->getCurrentPage() * 25, 25) as $entity) {
      $grid[] = $builder->view($entity, 'gallery_thumbnail', $langcode);
    }
    return [
      [
        'grid' => $grid,
        'pager' => ['#type' => 'pager', '#element' => 2],
        '#attached' => ['library' => ['labdoo_gallery/grid']],
        '#cache' => ['contexts' => ['url.query_args:page', 'user.permissions']],
      ],
    ];
  }

}
