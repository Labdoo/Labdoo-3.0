<?php

namespace Drupal\natiboo_migrate_data\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service to handle menu import logic.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MenuImport implements ImportInterface {

  /**
   * Constructs a new MenuImportService instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager service.
   * @param \Drupal\natiboo_migrate_data\Service\ImportHelper $importHelper
   *   The import helper.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ImportHelper $importHelper,
  ) {
  }

  /**
   * {@inheritDoc}
   */
  public function getFileData(): array {
    $data = $this->importHelper->getFileData();
    if (!isset($data['menu']) || !isset($data['links'])) {
      throw new \Exception('Invalid JSON format. The file must contain "menu" and "links".');
    }

    return $data;
  }

  /**
   * {@inheritDoc}
   */
  public function import(array $data, bool $overwrite = FALSE): void {
    $menuData = $data['menu'];
    $linksData = $data['links'];

    $menuStorage = $this->entityTypeManager->getStorage('menu');
    $linkStorage = $this->entityTypeManager->getStorage('menu_link_content');

    // If the menu does not exist, we create it.
    $menu = $menuStorage->load($menuData['id']);
    if (!$menu) {
      $menu = $menuStorage->create([
        'id' => $menuData['id'],
        'label' => $menuData['label'],
        'description' => $menuData['description'],
        'langcode' => $menuData['langcode'],
        'status' => $menuData['status'],
      ]);
      $menu->save();
    }

    // Imports the menu items.
    foreach ($linksData as $link) {
      $uuid = $link['uuid'][0]['value'] ?? NULL;
      if ($uuid === NULL) {
        continue;
      }

      // If the item exists, and it's not marked for overwriting, ignore it.
      $existingLink = $linkStorage->loadByProperties(['uuid' => $link['uuid'][0]['value']]);
      if (!empty($existingLink) && !$overwrite) {
        continue;
      }

      if ($existingLink) {
        $existingLink = reset($existingLink);
        $existingLink->delete();
      }

      $menuLink = $linkStorage->create($link);
      $menuLink->save();
    }
  }

}
