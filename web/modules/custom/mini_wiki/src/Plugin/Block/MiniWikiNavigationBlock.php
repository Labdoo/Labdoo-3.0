<?php

namespace Drupal\mini_wiki\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\mini_wiki\Entity\MiniWikiPage;
use Drupal\mini_wiki\Service\MiniWikiTreeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Provides a block for navigating mini Wiki pages.
 *
 * @Block(
 *   id = "mini_wiki_navigation_block",
 *   admin_label = @Translation("Wiki tree"),
 * )
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MiniWikiNavigationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a new miniWikiNavigationBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $routeMatch
   *   The current route.
   * @param \Drupal\mini_wiki\Service\MiniWikiTreeManager $wikiTreeManager
   *   The book manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected CurrentRouteMatch $routeMatch,
    protected MiniWikiTreeManager $wikiTreeManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('mini_wiki.tree_manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $entity = $this->routeMatch->getParameter('mini_wiki_page');

    if (!$entity instanceof MiniWikiPage) {
      return [];
    }

    $cacheTags = [
      sprintf(
        'wiki:%d',
        $entity->id()
      ),
    ];

    $parentLinks = $this->wikiTreeManager->getParentEntityUrl($entity);
    $childrenLinks = $this->wikiTreeManager->getChildrenEntitiesUrls($entity->id());

    $access = $this->entityTypeManager
      ->getAccessControlHandler('mini_wiki_page')
      ->createAccess(NULL, NULL, [], TRUE);
    $add_subpage = [
      '#type' => 'link',
      '#title' => $this->t('Add subpage'),
      '#url' => Url::fromRoute('entity.mini_wiki_page.add_form', ['parent' => $entity->id()]),
      '#attributes' => ['class' => ['button']],
      '#access' => $access,
    ];

    $build = [
      '#theme' => 'mini_wiki_navigation_block',
      '#entity_id' => $entity->id(),
      '#parent_link' => $parentLinks,
      '#children_links' => $childrenLinks,
      '#add_subpage' => $add_subpage,
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'contexts' => [
          'url.path',
          'session',
        ],
        'tags' => $cacheTags,
      ],
    ];
    CacheableMetadata::createFromRenderArray($build)
      ->merge(CacheableMetadata::createFromObject($access))
      ->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['route'];
  }

}
