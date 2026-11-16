<?php
require __DIR__ . '/../e2e/support/assert-isolated.php';
$manager = \Drupal::entityTypeManager();
$inventory = ['core' => \Drupal::VERSION, 'menus' => [], 'bundles' => [], 'entityTypes' => [], 'languages' => array_keys(\Drupal::languageManager()->getLanguages())];
foreach ($manager->getDefinitions() as $id => $definition) {
  if ($definition instanceof \Drupal\Core\Entity\ContentEntityTypeInterface) {
    $inventory['entityTypes'][$id] = ['label' => (string) $definition->getLabel(), 'links' => $definition->getLinkTemplates(), 'bundles' => array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo($id))];
  }
}
$tree = \Drupal::menuTree();
foreach ($manager->getStorage('menu')->loadMultiple() as $menu) {
  $params = (new \Drupal\Core\Menu\MenuTreeParameters())->onlyEnabledLinks();
  $walk = function ($items, $parents = []) use (&$walk, &$inventory, $menu) {
    foreach ($items as $item) {
      $link = $item->link;
      try { $url = $link->getUrlObject(); $href = $url->toString(); }
      catch (\Throwable $e) { $href = ''; }
      $inventory['menus'][] = ['menu' => $menu->id(), 'title' => $link->getTitle(), 'parents' => $parents, 'path' => $href, 'external' => isset($url) && $url->isExternal(), 'plugin' => $link->getPluginId()];
      $walk($item->subtree, [...$parents, $link->getTitle()]);
    }
  };
  $walk($tree->load($menu->id(), $params));
}
foreach ($manager->getStorage('node_type')->loadMultiple() as $bundle) {
  $fields = [];
  foreach (\Drupal::service('entity_field.manager')->getFieldDefinitions('node', $bundle->id()) as $name => $definition) {
    $fields[$name] = ['type' => $definition->getType(), 'required' => $definition->isRequired(), 'settings' => $definition->getSettings()];
  }
  $inventory['bundles'][$bundle->id()] = ['label' => $bundle->label(), 'fields' => $fields];
}
$state=\Drupal\Core\Site\Settings::get('labdoo_e2e_state');
file_put_contents($state . '/inventory.json', json_encode($inventory, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Discovered ' . count($inventory['menus']) . ' menu entries, ' . count($inventory['bundles']) . " node bundles.\n";
