<?php

namespace Drupal\labdoo_admin_toolbar\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'Admin Toolbar' block.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @Block(
 *   id = "labdoo_admin_toolbar_block",
 *   admin_label = @Translation("Labdoo Admin Toolbar"),
 *   category = @Translation("Labdoo")
 * )
 */
class AdminToolbarBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The menu link tree service.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuLinkTree;

  /**
   * Constructs a new AdminToolbarBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menu_link_tree
   *   The menu link tree service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MenuLinkTreeInterface $menu_link_tree) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->menuLinkTree = $menu_link_tree;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('menu.link_tree')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Load the admin menu tree.
    $menu_name = 'admin';
    $parameters = new MenuTreeParameters();
    $parameters->setMinDepth(2);
    $parameters->setMaxDepth(4);
    $parameters->onlyEnabledLinks();

    $tree = $this->menuLinkTree->load($menu_name, $parameters);
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:checkAccess'],
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $this->menuLinkTree->transform($tree, $manipulators);

    // Build the menu items.
    $menu_items = [];
    foreach ($tree as $item) {
      if ($item->access->isAllowed()) {
        $menu_items[] = $this->buildMenuItem($item);
      }
    }

    // Return the render array.
    $build = [
      '#theme' => 'labdoo_admin_toolbar_block',
      '#menu_items' => $menu_items,
      '#attached' => [
        'library' => [
          'labdoo_admin_toolbar/labdoo_admin_toolbar',
        ],
      ],
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];

    return $build;
  }

  /**
   * Builds a menu item render array from a menu link tree element.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement $item
   *   The menu link tree element.
   *
   * @return array
   *   A render array representing the menu item.
   */
  protected function buildMenuItem($item) {
    $menu_item = [
      'title' => $item->link->getTitle(),
      'url' => $item->link->getUrlObject(),
      'description' => $item->link->getDescription(),
      'children' => [],
    ];

    // Process children if any.
    if ($item->hasChildren && !empty($item->subtree)) {
      foreach ($item->subtree as $child) {
        if ($child->access->isAllowed()) {
          $menu_item['children'][] = $this->buildMenuItem($child);
        }
      }
    }

    return $menu_item;
  }

}
