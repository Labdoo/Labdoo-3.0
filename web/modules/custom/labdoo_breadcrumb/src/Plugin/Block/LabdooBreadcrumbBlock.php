<?php

namespace Drupal\labdoo_breadcrumb\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Labdoo Breadcrumb' block.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Block(
 *   id = "labdoo_breadcrumb_block",
 *   admin_label = @Translation("Labdoo Breadcrumb"),
 *   category = @Translation("Labdoo"),
 * )
 */
class LabdooBreadcrumbBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * Constructs a new LabdooBreadcrumbBlock instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    RouteMatchInterface $route_match
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $route_name = $this->routeMatch->getRouteName();
    $items = [];

    // Always add the Home link.
    $items[] = [
      'text' => $this->t('Home'),
      'url' => Url::fromRoute('<front>')->toString(),
    ];

    // Handle Wiki pages.
    if ($route_name === 'entity.mini_wiki_page.canonical') {
      $wiki_page = $this->routeMatch->getParameter('mini_wiki_page');

      $items[] = [
        'text' => $this->t('Wiki'),
        'url' => Url::fromRoute('view.wiki.page_1')->toString(),
      ];

      if ($wiki_page instanceof \Drupal\Core\Entity\EntityInterface) {
        $items[] = [
          'text' => $wiki_page->label(),
          'url' => NULL,
        ];
      }
      elseif (is_numeric($wiki_page)) {
        $storage = \Drupal::entityTypeManager()->getStorage('mini_wiki_page');
        $entity = $storage->load($wiki_page);
        if ($entity) {
          $items[] = [
            'text' => $entity->label(),
            'url' => NULL,
          ];
        }
      }
    }

    // Handle specific node types.
    if ($route_name === 'entity.node.canonical') {
      $node = $this->routeMatch->getParameter('node');
      if ($node instanceof NodeInterface) {
        $type = $node->getType();

        switch ($type) {
          case 'edoovillage':
            $items[] = [
              'text' => $this->t('Edoovillages'),
              'url' => Url::fromRoute('view.edoovillages.page_1')->toString(),
            ];
            break;

          case 'hub':
            $items[] = [
              'text' => $this->t('Hubs'),
              'url' => Url::fromRoute('view.hubs_dashboard.page_1')->toString(),
            ];
            break;

          case 'dootronic':
            $items[] = [
              'text' => $this->t('Dootronics'),
              'url' => Url::fromRoute('view.dootronics_dashboard.page_1')->toString(),
            ];
            break;

          case 'dootrip':
            $items[] = [
              'text' => $this->t('Dootrips'),
              'url' => Url::fromRoute('view.dootrips_dashboard.page_1')->toString(),
            ];
            break;

          case 'team':
          case 'team_post':
            $items[] = [
              'text' => $this->t('Teams'),
              'url' => Url::fromRoute('view.teams.page_1')->toString(),
            ];
            break;
        }

        // Add current node title as last item (no link).
        if (in_array($type, ['edoovillage', 'hub', 'dootronic', 'dootrip', 'team', 'team_post'])) {
          $items[] = [
            'text' => $node->label(),
            'url' => NULL,
          ];
        }
      }
    }

    // Handle Views.
    if (strpos($route_name, 'view.') === 0) {
      $title = \Drupal::service('title_resolver')->getTitle(\Drupal::request(), $this->routeMatch->getRouteObject());
      if ($title) {
        $items[] = [
          'text' => $title,
          'url' => NULL,
        ];
      }
    }

    // If we don't have any breadcrumb items, don't render the block.
    /*
    if (count($items) <= 1) {
      return [];
    }
    */

    return [
      '#theme' => 'labdoo_breadcrumb',
      '#items' => $items,
      '#cache' => [
        'contexts' => [
          'url.path',
          'user.permissions',
          'route',
        ],
        'tags' => $this->getCacheTags(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $tags = parent::getCacheTags();

    // Add the node cache tag if we're on a node page.
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      $tags = Cache::mergeTags($tags, $node->getCacheTags());
    }

    // Add the wiki page cache tag if we're on a wiki page.
    $wiki_page = $this->routeMatch->getParameter('mini_wiki_page');
    if ($wiki_page instanceof \Drupal\Core\Entity\EntityInterface) {
      $tags = Cache::mergeTags($tags, $wiki_page->getCacheTags());
    }

    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), [
      'url.path',
      'user.permissions',
      'route',
    ]);
  }

}
