<?php

require __DIR__ . '/harden.php';
$state = \Drupal\Core\Site\Settings::get('labdoo_e2e_state');
if (\Drupal\Core\Site\Settings::get('labdoo_e2e_variant') !== 'integration') {
  throw new RuntimeException('Integration only');
}
$storage = \Drupal::entityTypeManager()->getStorage('node');
$f = json_decode(file_get_contents($state . '/integration-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);
\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load($f['accounts']['admin']['id']));
$samples = [];
foreach ([0, 1] as $count) {
  $node = $storage->load($f['nodes']['Trip']['id'])->createDuplicate();
  $node->setOwnerId($f['accounts']['admin']['id']);
  $node->set('field_laptops', $count ? [['target_id' => $f['nodes']['Laptop Main']['id']]] : []);
  $node->skip_geocoding_enqueue = TRUE;
  $node->skip_recompute_enqueue = TRUE;
  $node->save();
  // Model a migrated node whose references exist but its stored counter is empty.
  // Saving normally recalculates the counter. Remove only this new fixture's
  // field rows to reproduce the state of an older import without that field.
  foreach (['node__field_number_of_doojects_assigne', 'node_revision__field_number_of_doojects_assigne'] as $table) {
    \Drupal::database()->delete($table)->condition('entity_id', $node->id())->execute();
  }
  $storage->resetCache([$node->id()]);
  if (!$storage->load($node->id())->get('field_number_of_doojects_assigne')->isEmpty()) {
    throw new RuntimeException('Expected empty legacy counter');
  }
  $samples[] = ['id' => (int) $node->id(), 'expected' => $count];
}
file_put_contents($state . '/assigned-counter-fixtures.json', json_encode($samples, JSON_THROW_ON_ERROR));
echo 'Legacy assigned counter fixtures ready.' . PHP_EOL;
