<?php
require __DIR__ . '/../e2e/support/harden.php';
// Tear down only the uniquely prefixed entities created by this suite.
$manager = \Drupal::entityTypeManager();
foreach (['comment'=>['subject','E2E comment '], 'node'=>['title','E2E lifecycle '], 'taxonomy_term'=>['name','E2E term '], 'block_content'=>['info','E2E block '], 'menu_link_content'=>['title','E2E menu '], 'media'=>['name','E2E media '], 'user'=>['name','e2e-lifecycle-']] as $type=>[$field,$prefix]) {
  $storage=$manager->getStorage($type);
  $ids=$storage->getQuery()->accessCheck(FALSE)->condition($field,$prefix.'%','LIKE')->execute();
  if ($ids) { $storage->delete($storage->loadMultiple($ids)); echo 'Cleaned '.count($ids).' '.$type." fixtures.\n"; }
}
