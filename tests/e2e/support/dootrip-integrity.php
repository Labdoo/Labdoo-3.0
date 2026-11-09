<?php

require __DIR__ . '/harden.php';

use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;

$file = Settings::get('labdoo_e2e_state') . '/dootrip-integrity-fixture.json';
if (getenv('LABDOO_PROGRAM_TEST_OP') === 'cleanup') {
  $fixture = json_decode(file_get_contents($file), TRUE);
  Node::load($fixture['nid'])?->delete();
  echo 'PROGRAM_JSON:' . json_encode(['cleaned' => TRUE]);
  return;
}
$fixtures = json_decode(file_get_contents(Settings::get('labdoo_e2e_state') . '/integration-fixtures.json'), TRUE);
$source = Node::load($fixtures['nodes']['Trip']['id']);
$node = $source->createDuplicate();
$node->skip_geocoding_enqueue = TRUE;
$node->skip_recompute_enqueue = TRUE;
$node->skip_action_generation = TRUE;
$transaction = \Drupal::database()->startTransaction();
$node->save();
if (\Drupal::lock()->lockMayBeAvailable('labdoo.dootrip.id')) {
  throw new RuntimeException('Trip lock released before the root transaction committed.');
}
unset($transaction);
if (!\Drupal::lock()->lockMayBeAvailable('labdoo.dootrip.id')) {
  throw new RuntimeException('Trip lock not released after commit.');
}
if ($source->getTitle() === $node->getTitle()) {
  throw new RuntimeException('Trip clone retained its source public identifier.');
}
$fixture = ['nid' => (int) $node->id(), 'title' => $node->getTitle()];
file_put_contents($file, json_encode($fixture));
echo 'PROGRAM_JSON:' . json_encode($fixture);
