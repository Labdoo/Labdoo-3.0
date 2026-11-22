<?php

/**
 * @file
 * Manages a disposable trip for date validation and persistence assertions.
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;

require __DIR__ . '/harden.php';

$state = Settings::get('labdoo_e2e_state');
$file = $state . '/dootrip-date-fixture.json';
$operation = getenv('LABDOO_DATE_TEST_OP');
if ($operation === 'create') {
  if (is_file($file)) {
    throw new RuntimeException('Clean the previous date fixture first.');
  }
  $fixtures = json_decode(file_get_contents($state . '/integration-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  $node = Node::load($fixtures['nodes']['Trip']['id'])->createDuplicate();
  $node->skip_geocoding_enqueue = TRUE;
  $node->skip_recompute_enqueue = TRUE;
  $node->skip_action_generation = TRUE;
  $node->save();
  file_put_contents($file, json_encode(['nid' => (int) $node->id()], JSON_THROW_ON_ERROR));
}
elseif (!in_array($operation, ['read', 'cleanup'], TRUE)) {
  throw new RuntimeException('Unsupported date fixture operation.');
}
$fixture = json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
$node = Node::load($fixture['nid']);
if ($operation === 'cleanup') {
  $node?->delete();
  unlink($file);
  echo 'DATES_JSON:' . json_encode(['cleaned' => TRUE], JSON_THROW_ON_ERROR);
  return;
}
echo 'DATES_JSON:' . json_encode($fixture + [
  'departure' => $node->get('field_departure_date')->value,
  'arrival' => $node->get('field_arrival_date')->value,
], JSON_THROW_ON_ERROR);
