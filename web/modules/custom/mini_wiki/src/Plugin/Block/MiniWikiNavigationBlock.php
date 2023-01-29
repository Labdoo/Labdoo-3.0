<?php

namespace Drupal\mini_wiki\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected CurrentRouteMatch $routeMatch,
    protected MiniWikiTreeManager $wikiTreeManager
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
      $container->get('mini_wiki.tree_manager')
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

    return [
      '#theme' => 'mini_wiki_navigation_block',
      '#entity_id' => $entity->id(),
      '#parent_link' => $parentLinks,
      '#children_links' => $childrenLinks,
      '#cache' => [
        'max-age' => Cache::PERMANENT,
        'contexts' => [
          'url.path',
          'session',
        ],
        'tags' => $cacheTags,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return ['route'];
  }

}
