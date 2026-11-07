<?php

require __DIR__ . '/assert-isolated.php';
$state = \Drupal\Core\Site\Settings::get('labdoo_e2e_state');
$variant = \Drupal\Core\Site\Settings::get('labdoo_e2e_variant');
$fixtures = json_decode(file_get_contents($state . '/' . $variant . '-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$device = $fixtures['nodes']['Laptop Main'];
foreach (['default', 'page_1', 'block_1'] as $display) {
  $view = \Drupal\views\Views::getView('dootronics_dashboard');
  $view->setDisplay($display);
  $view->setExposedInput(['combine' => $device['title']]);
  $view->setItemsPerPage(0);
  $view->execute();
  if (!empty($view->build_info['fail'])) {
    throw new RuntimeException('Search failed for dashboard display ' . $display);
  }
  $ids = array_map(static fn($row) => (int) $row->_entity->id(), $view->result);
  if ($ids !== [(int) $device['id']]) {
    throw new RuntimeException('Search result mismatch for dashboard display ' . $display);
  }
  echo 'Filtered dashboard display passed: ' . $display . PHP_EOL;
}
