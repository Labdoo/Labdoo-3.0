<?php

/**
 * @file
 * Exercises action generation only with disposable isolated-clone nodes.
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

require __DIR__ . '/harden.php';

$token = getenv('LABDOO_ACTION_TEST_TOKEN');
if (!is_string($token) || !preg_match('/^[a-f0-9]{16}$/', $token)) {
  throw new RuntimeException('A unique action fixture token is required.');
}
$file = Settings::get('labdoo_e2e_state') . '/action-contract-' . $token . '.json';
$operation = getenv('LABDOO_ACTION_TEST_OP');
if ($operation === 'cleanup') {
  if (is_file($file)) {
    $state = json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
    foreach (array_reverse($state['owned']) as $owned) {
      $node = Node::load($owned['nid']);
      if ($node !== NULL) {
        if ($node->uuid() !== $owned['uuid']) {
          throw new RuntimeException('Refusing to delete a different fixture node.');
        }
        $node->delete();
      }
    }
    unlink($file);
  }
  echo 'ACTION_JSON:' . json_encode(['cleaned' => TRUE], JSON_THROW_ON_ERROR);
  return;
}
if ($operation !== 'create' || is_file($file)) {
  throw new RuntimeException('Unsupported or duplicate action fixture operation.');
}
$state = ['owned' => [], 'cases' => [], 'updates' => []];
$remember = static function (NodeInterface $node) use (&$state, $file): void {
  $state['owned'][] = ['nid' => (int) $node->id(), 'uuid' => $node->uuid()];
  file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR));
  chmod($file, 0600);
};
$fixtures = json_decode(file_get_contents(Settings::get('labdoo_e2e_state') . '/integration-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);
foreach (['Laptop Main' => 'Dooject', 'Trip' => 'Dootrip', 'School' => 'Edoovillage', 'Hub Alpha' => 'Hub'] as $key => $type) {
  $node = Node::load($fixtures['nodes'][$key]['id'])->createDuplicate();
  $node->setOwnerId($fixtures['accounts']['admin']['id']);
  $node->setCreatedTime(time());
  $node->setChangedTime(time());
  $node->skip_geocoding_enqueue = TRUE;
  $node->skip_recompute_enqueue = TRUE;
  if ($type === 'Dooject') {
    $node->set('field_dootronic_status', 'S0');
  }
  elseif ($type === 'Dootrip') {
    $node->set('field_laptops', []);
  }
  $node->save();
  $remember($node);
  $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)
    ->condition('type', 'action')
    ->condition('body.value', '%href="/node/' . $node->id() . '"%', 'LIKE')
    ->execute();
  foreach ($ids as $id) {
    $remember(Node::load($id));
  }
  if (count($ids) !== 1) {
    throw new RuntimeException('Exactly one action must be persisted for each source node.');
  }
  $action = Node::load(reset($ids));
  if ($action->get('field_action_type')->value !== $type
    || (int) $action->getOwnerId() !== (int) $node->getOwnerId()
    || !$action->isPublished()) {
    throw new RuntimeException('Generated action type, owner or publication state is incorrect.');
  }
  $state['cases'][] = [
    'source' => (int) $node->id(),
    'title' => $node->label(),
    'action' => (int) $action->id(),
    'type' => $type,
  ];
  if (in_array($type, ['Dooject', 'Dootrip'], TRUE)) {
    if ($type === 'Dooject') {
      $node->set('field_dootronic_status', 'S2');
      $fragment = 'was sanitized';
    }
    else {
      $node->set('field_laptops', [$fixtures['nodes']['Laptop Main']['id']]);
      $fragment = 'was updated';
    }
    $node->save();
    $updatedIds = \Drupal::entityQuery('node')->accessCheck(FALSE)
      ->condition('type', 'action')
      ->condition('body.value', '%href="/node/' . $node->id() . '"%', 'LIKE')
      ->execute();
    $newIds = array_diff($updatedIds, $ids);
    foreach ($newIds as $id) {
      $remember(Node::load($id));
    }
    if (count($newIds) !== 1) {
      throw new RuntimeException('A meaningful update must create exactly one new action.');
    }
    $update = Node::load(reset($newIds));
    if (!str_contains($update->label(), $fragment)) {
      throw new RuntimeException('The action must describe the source state transition.');
    }
    $state['updates'][] = [
      'source' => (int) $node->id(),
      'title' => $node->label(),
      'action' => (int) $update->id(),
      'type' => $type,
    ];
    $ids = $updatedIds;
  }
  // Saving the same state again must not create duplicate activity.
  $node->save();
  $repeatedIds = \Drupal::entityQuery('node')->accessCheck(FALSE)
    ->condition('type', 'action')
    ->condition('body.value', '%href="/node/' . $node->id() . '"%', 'LIKE')
    ->execute();
  foreach (array_diff($repeatedIds, $ids) as $id) {
    $remember(Node::load($id));
  }
  if (array_diff($repeatedIds, $ids) !== [] || array_diff($ids, $repeatedIds) !== []) {
    throw new RuntimeException('An unchanged source state created duplicate activity.');
  }
}
$foreignTitle = 'E2E other action owner ' . $token;
$foreign = Node::create([
  'type' => 'action',
  'title' => $foreignTitle,
  'uid' => $fixtures['accounts']['wiki']['id'],
  'field_labdooer_action' => $fixtures['accounts']['wiki']['id'],
  'field_action_type' => 'Dooject',
  'field_stream_it' => TRUE,
  'body' => ['value' => '<p>' . $foreignTitle . '</p>', 'format' => 'full_html'],
]);
$foreign->save();
$remember($foreign);
echo 'ACTION_JSON:' . json_encode([
  'cases' => $state['cases'],
  'updates' => $state['updates'],
  'foreign_title' => $foreignTitle,
], JSON_THROW_ON_ERROR);
