<?php

namespace Drupal\natiboo_migrate_data\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service to handle menu export logic.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MenuExport {

  /**
   * Constructs a new MenuExportService instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Exports a single menu by its machine name.
   *
   * @param string $menuName
   *   The machine name of the menu.
   *
   * @return array
   *   The export data for the menu in array format.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Exception
   */
  public function export(string $menuName): array {
    $menu = $this->entityTypeManager
      ->getStorage('menu')
      ->load($menuName);

    if (!$menu) {
      throw new \Exception("Menu '{$menuName}' not found.");
    }

    // Get the menu metadata.
    $menu_data = [
      'id' => $menu->id(),
      'label' => $menu->label(),
      'description' => $menu->getDescription(),
      'langcode' => $menu->language()->getId(),
      'status' => $menu->status(),
    ];

    // Export menu items.
    $menu_links = $this->entityTypeManager
      ->getStorage('menu_link_content')
      ->loadByProperties(['menu_name' => $menuName]);

    $links_data = [];
    foreach ($menu_links as $menu_link) {
      $links_data[] = $menu_link->toArray();
    }

    // Combine menu metadata and links.
    return [
      'menu' => $menu_data,
      'links' => $links_data,
    ];
  }

}
