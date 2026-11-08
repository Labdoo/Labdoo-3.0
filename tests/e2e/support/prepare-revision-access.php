<?php

require __DIR__ . '/harden.php';
$state = \Drupal\Core\Site\Settings::get('labdoo_e2e_state');
$kind = \Drupal\Core\Site\Settings::get('labdoo_e2e_variant');
if ($kind !== 'integration') { throw new RuntimeException('Revision fixture belongs to integration'); }
$path = $state . '/revision-access-fixture.json';
$storage = \Drupal::entityTypeManager()->getStorage('node');
if (is_file($path)) {
  $fixture = json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
  if ($storage->load($fixture['id']) && $storage->loadRevision($fixture['old_revision'])) {
    echo 'Historical revision fixture ready.' . PHP_EOL;
    return;
  }
}
$f = json_decode(file_get_contents($state . '/integration-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);
\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load($f['accounts']['admin']['id']));
$node = $storage->load($f['nodes']['Trip']['id'])->createDuplicate();
$node->setOwnerId($f['accounts']['admin']['id']);
$node->setTitle('E2E revision access');
$node->skip_geocoding_enqueue = TRUE;
$node->skip_recompute_enqueue = TRUE;
$node->setNewRevision(TRUE);
$node->setRevisionCreationTime(time() - 120);
$node->setRevisionLogMessage('E2E historical revision');
$node->save();
$old = (int) $node->getRevisionId();
$node->setNewRevision(TRUE);
$node->setRevisionCreationTime(time() - 60);
$node->setRevisionLogMessage('E2E current revision');
$node->set('field_dootrip_capacity', 6);
$node->save();
$fixture = ['id' => (int) $node->id(), 'old_revision' => $old, 'current_revision' => (int) $node->getRevisionId()];
file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Historical revision fixture created.' . PHP_EOL;
