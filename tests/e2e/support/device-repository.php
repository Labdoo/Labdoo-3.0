<?php

/**
 * @file
 * Exercises device imports only in the guarded local regression clone.
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;

require __DIR__ . '/harden.php';

$state = Settings::get('labdoo_e2e_state');
$file = $state . '/device-repository-fixture.json';
$operation = getenv('LABDOO_DEVICE_TEST_OP');
$repository = \Drupal::service('labdoo_dootronics.repository');
if ($operation === 'cleanup') {
  if (is_file($file)) {
    $fixture = json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
    Node::load($fixture['nid'])?->delete();
    unlink($file);
  }
  echo 'DEVICE_JSON:' . json_encode(['cleaned' => TRUE], JSON_THROW_ON_ERROR);
  return;
}
if ($operation !== 'create') {
  throw new RuntimeException('Unsupported device repository test operation.');
}
if (is_file($file)) {
  throw new RuntimeException('Clean the previous isolated device fixture first.');
}
$fixtures = json_decode(file_get_contents($state . '/integration-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$node = Node::load($fixtures['nodes']['Laptop Main']['id'])->createDuplicate();
$node->skip_geocoding_enqueue = TRUE;
$node->skip_recompute_enqueue = TRUE;
$node->skip_action_generation = TRUE;
$repository->setDootronicExternalData($node, [
  'serial_no' => 'E2E-EXTERNAL-SERIAL',
  'cpu_speed_mhz' => 2400,
  'memory_size_mb' => 4096,
  'os_version' => 'E2E Linux',
  'keyboard_layout' => 'E2E Spanish',
  'battery_voltage' => 12,
  'battery_energy_mwh' => 48000,
], '', FALSE);
$node->save();
$fixture = ['nid' => (int) $node->id()];
file_put_contents($file, json_encode($fixture, JSON_THROW_ON_ERROR));

// Exercise the real field definitions and zero-voltage path without saving it.
if ($repository->getfieldAllowedValues('node', 'dootronic', 'field_volts') !== []) {
  throw new RuntimeException('A decimal field must not have list values.');
}
$zero = $node->createDuplicate();
$repository->setDootronicExternalData($zero, [
  'battery_voltage' => 0,
  'battery_energy_mwh' => 48000,
], '', FALSE);
if ((float) $zero->get('field_volts')->value !== 0.0 || (int) $zero->get('field_amp_hours')->value !== 4000) {
  throw new RuntimeException('Unknown voltage must preserve the existing battery capacity.');
}
echo 'DEVICE_JSON:' . json_encode($fixture, JSON_THROW_ON_ERROR);
