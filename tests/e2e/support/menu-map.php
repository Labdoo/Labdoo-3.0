<?php

declare(strict_types=1);

/**
 * @file
 * Isolated fixtures for map filtering, cache invalidation and user variation.
 */

require __DIR__ . '/assert-isolated.php';

$state = \Drupal\Core\Site\Settings::get('labdoo_e2e_state');
$manifest = $state . '/menu-map-fixture.json';
$operation = getenv('LABDOO_MENU_MAP_OP') ?: 'inspect';
$fixtures = json_decode(file_get_contents($state . '/integration-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$storage = \Drupal::entityTypeManager()->getStorage('node');
$data = is_file($manifest) ? json_decode(file_get_contents($manifest), TRUE, 512, JSON_THROW_ON_ERROR) : [];

if ($operation === 'prepare') {
  if (is_file($manifest)) {
    throw new RuntimeException('Clean the existing map fixture first.');
  }
  $prototype = $storage->load($fixtures['nodes']['Laptop Main']['id']);
  foreach (['basic', 'laptop', 'unpublished'] as $name) {
    $owner = $fixtures['accounts'][$name === 'unpublished' ? 'basic' : $name]['id'];
    $node = $prototype->createDuplicate();
    $node->setOwnerId($owner)->setPublished();
    $node->set('field_model', 'E2E menu map fixture');
    $node->set('field_location', ['value' => 'POINT (3 0)']);
    $node->skip_geocoding_enqueue = TRUE;
    $node->skip_recompute_enqueue = TRUE;
    foreach (['field_edoo_additional_editors', 'field_edoo_additional_followers', 'field_edoo_additional_notif_em', 'field_edoovillage_photo_album', 'field_dootrips', 'field_laptops'] as $field) {
      if ($node->hasField($field)) { $node->set($field, []); }
    }
    if ($name === 'unpublished') { $node->setUnpublished(); }
    $node->save();
    $data[$name] = ['id' => (int) $node->id(), 'title' => $node->label(), 'owner' => (int) $owner, 'roles' => \Drupal\user\Entity\User::load($owner)->getRoles(TRUE)];
    file_put_contents($manifest, json_encode($data, JSON_THROW_ON_ERROR));
    chmod($manifest, 0600);
  }
}
elseif (in_array($operation, ['move', 'unpublish', 'publish'], TRUE)) {
  $node = $storage->load($data['basic']['id']);
  if (!$node || $node->get('field_model')->value !== 'E2E menu map fixture') {
    throw new RuntimeException('Unexpected fixture entity.');
  }
  $node->skip_geocoding_enqueue = TRUE;
  $node->skip_recompute_enqueue = TRUE;
  if ($operation === 'move') { $node->set('field_location', ['value' => 'POINT (8 1)']); }
  elseif ($operation === 'unpublish') { $node->setUnpublished(); }
  else { $node->setPublished(); }
  $node->save();
}
elseif (in_array($operation, ['directory-allow', 'directory-deny'], TRUE)) {
  $account = \Drupal\user\Entity\User::load($data['basic']['owner']);
  if ($operation === 'directory-allow') { $account->addRole('superhub_manager'); }
  else { $account->removeRole('superhub_manager'); }
  $account->save();
}
elseif ($operation === 'cleanup') {
  foreach ($data as $entry) {
    $node = $storage->load($entry['id']);
    if ($node) {
      if ($node->get('field_model')->value !== 'E2E menu map fixture' || (int) $node->getOwnerId() !== $entry['owner']) {
        throw new RuntimeException('Refusing to delete an unexpected map fixture.');
      }
      $node->delete();
    }
  }
  if (isset($data['basic'])) {
    $account = \Drupal\user\Entity\User::load($data['basic']['owner']);
    if ($account->getRoles(TRUE) !== $data['basic']['roles']) { $account->set('roles', $data['basic']['roles'])->save(); }
  }
  if (is_file($manifest)) { unlink($manifest); }
}
elseif ($operation !== 'inspect') {
  throw new RuntimeException('Unknown map fixture operation.');
}
echo 'MENU_MAP:' . json_encode($data, JSON_THROW_ON_ERROR) . PHP_EOL;
