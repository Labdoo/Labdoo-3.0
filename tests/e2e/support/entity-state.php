<?php
require __DIR__ . '/assert-isolated.php';
$input = json_decode(getenv('LABDOO_E2E_COMMAND'), TRUE, 512, JSON_THROW_ON_ERROR);
if (isset($input['path'])) {
  $route = \Drupal::service('path_alias.manager')->getPathByAlias(preg_replace('#^/en/#', '/', $input['path']), 'en');
  if (!preg_match('#^/node/(\d+)$#', $route, $matches)) throw new \RuntimeException('Cannot resolve cloned node path');
  $input['id'] = (int)$matches[1];
}
$storage = \Drupal::entityTypeManager()->getStorage('node');
$properties = isset($input['id']) ? ['nid' => (int)$input['id']] : ['title' => $input['title']];
$nodes = $storage->loadByProperties($properties);
$result = [];
foreach ($nodes as $node) {
  $galleries = $storage->loadByProperties(['type' => 'gallery', 'field_parent' => $node->id()]);
  $result[] = ['id' => (int)$node->id(), 'title' => $node->label(), 'bundle' => $node->bundle(), 'galleries' => array_map('intval', array_keys($galleries)), 'album' => $node->hasField('field_edoovillage_photo_album') ? $node->get('field_edoovillage_photo_album')->target_id : NULL];
}
echo 'E2E_JSON:' . json_encode($result) . PHP_EOL;
