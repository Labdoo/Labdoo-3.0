<?php

namespace Drupal\labdoo_common\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides a custom breadcrumb builder for Labdoo.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class LabdooBreadcrumbBuilder implements BreadcrumbBuilderInterface {
  use StringTranslationTrait;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * Constructs a LabdooBreadcrumbBuilder object.
   */
  public function __construct(LanguageManagerInterface $language_manager, RouteMatchInterface $route_match) {
    $this->languageManager = $language_manager;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL) {
    $route_name = $route_match->getRouteName();

    // Apply to wiki pages.
    if ($route_match->getParameter('mini_wiki_page') || $route_name === 'entity.mini_wiki_page.canonical') {
      return TRUE;
    }

    // Apply to specific node types that need a dashboard parent.
    if ($route_name === 'entity.node.canonical') {
      $node = $route_match->getParameter('node');
      if ($node instanceof \Drupal\node\NodeInterface) {
        $type = $node->getType();
        if (in_array($type, ['edoovillage', 'hub', 'dootronic', 'dootrip', 'team', 'team_post'])) {
          return TRUE;
        }
      }
    }

    // Apply to specific views that should show Home and current title.
    if (strpos($route_name, 'view.') === 0) {
      return TRUE;
    }

    $path = \Drupal::service('path.current')->getPath();
    // Only apply to /content if it's likely a wiki page alias or similar.
    if (strpos($path, '/content') !== FALSE) {
      // Avoid applying to administrative paths.
      if (strpos($path, '/admin/') !== FALSE) {
        return FALSE;
      }
      // If it's a node route, we already handled it above if it's one of our types.
      // If it's another node type, we probably don't want to interfere.
      if ($route_name === 'entity.node.canonical') {
        return FALSE;
      }
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $links = [];

    $breadcrumb->addCacheContexts(['url.path', 'languages:language_interface', 'user.permissions', 'route']);

    // Link to home
    $links[] = Link::createFromRoute($this->t('Home'), '<front>');

    $route_name = $route_match->getRouteName();

    // Special case for Wiki
    if ($wiki_page = $route_match->getParameter('mini_wiki_page')) {
      $links[] = Link::createFromRoute($this->t('Wiki'), 'view.wiki.page_1');

      // Add the current page title as the last element.
      if ($wiki_page instanceof \Drupal\Core\Entity\EntityInterface) {
        $links[] = Link::createFromRoute($wiki_page->label(), '<none>');
      }
      else {
        // If it's just an ID, try to load it.
        $storage = \Drupal::entityTypeManager()->getStorage('mini_wiki_page');
        $entity = $storage->load($wiki_page);
        if ($entity) {
          $links[] = Link::createFromRoute($entity->label(), '<none>');
        }
      }

      return $breadcrumb->setLinks($links);
    }

    // Handle Nodes with specific parents
    if ($route_name === 'entity.node.canonical') {
      $node = $route_match->getParameter('node');
      if ($node instanceof \Drupal\node\NodeInterface) {
        $type = $node->getType();
        switch ($type) {
          case 'edoovillage':
            $links[] = Link::createFromRoute($this->t('Edoovillages'), 'view.edoovillages.page_1');
            break;
          case 'hub':
            $links[] = Link::createFromRoute($this->t('Hubs'), 'view.hubs_dashboard.page_1');
            break;
          case 'dootronic':
            $links[] = Link::createFromRoute($this->t('Dootronics'), 'view.dootronics_dashboard.page_1');
            break;
          case 'dootrip':
            $links[] = Link::createFromRoute($this->t('Dootrips'), 'view.dootrips_dashboard.page_1');
            break;
          case 'team':
          case 'team_post':
            $links[] = Link::createFromRoute($this->t('Teams'), 'view.teams.page_1');
            break;
        }
        $links[] = Link::createFromRoute($node->label(), '<none>');
        $breadcrumb->addCacheableDependency($node);
        return $breadcrumb->setLinks($links);
      }
    }

    // Handle Views
    if (strpos($route_name, 'view.') === 0) {
      $title = \Drupal::service('title_resolver')->getTitle(\Drupal::request(), $route_match->getRouteObject());
      if ($title) {
        $links[] = Link::createFromRoute($title, '<none>');
      }
      return $breadcrumb->setLinks($links);
    }

    // Generic /content handling (if still needed)
    $path = \Drupal::service('path.current')->getPath();
    if (strpos($path, '/content') !== FALSE) {
      // If we are here, it's probably a legacy path or something not caught above.
      // We avoid adding "Back to site" or edit links.
    }

    return $breadcrumb->setLinks($links);
  }

}