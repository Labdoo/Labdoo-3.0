<?php

require __DIR__ . '/harden.php';

use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;

$state = Settings::get('labdoo_e2e_state');
$file = $state . '/edoovillage-integrity-fixture.json';
$op = getenv('LABDOO_PROGRAM_TEST_OP') ?: 'create';
if ($op === 'cleanup') {
  $fixture = json_decode(file_get_contents($file), TRUE);
  foreach ($fixture['created'] as $nid) {
    Node::load($nid)?->delete();
  }
  echo 'PROGRAM_JSON:' . json_encode(['cleaned' => TRUE]);
  return;
}
$source = Node::load(746);
$manager = \Drupal::service('labdoo_edoovillage.sequence_manager');
$expected = $manager->get();
$manager->commit();
$node = $source->createDuplicate();
$node->setTitle('Clone of St. Pauls Primary and Secondary School');
$node->set('field_country', 'DE');
$node->set('field_city', 'Bonn');
$node->set('field_project_summary', str_repeat('é', 240));
$node->skip_geocoding_enqueue = TRUE;
$node->skip_recompute_enqueue = TRUE;
$node->skip_action_generation = TRUE;
$transaction = \Drupal::database()->startTransaction();
$node->save();
if (\Drupal::lock()->lockMayBeAvailable('labdoo.edoovillage.id')) {
  throw new RuntimeException('Sequence lock released before root transaction committed.');
}
unset($transaction);
if (!\Drupal::lock()->lockMayBeAvailable('labdoo.edoovillage.id')) {
  throw new RuntimeException('Sequence lock not released after commit.');
}
if (!str_starts_with($node->getTitle(), 'Edoovillage #' . $expected . ' - Germany, Bonn:') || mb_strlen($node->getTitle()) > 255) {
  throw new RuntimeException('Generated clone title or length is incorrect.');
}
$fixture = ['created' => [(int) $node->id()], 'nid' => (int) $node->id(), 'number' => $expected, 'title_length' => mb_strlen($node->getTitle()), 'previous_nid' => \Drupal::service('labdoo_common.repository.common')->getPreviousEntity((int) $node->id(), 'edoovillage'), 'next_nid' => \Drupal::service('labdoo_common.repository.common')->getNextEntity((int) $node->id(), 'edoovillage')];
file_put_contents($file, json_encode($fixture));
echo 'PROGRAM_JSON:' . json_encode($fixture);
