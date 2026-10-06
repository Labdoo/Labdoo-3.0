<?php

/**
 * One-time correction of a V3 title that diverged from the same V2 device.
 *
 * Run with drush scr. Defaults to an audit; set LABDOO_APPLY_REPAIR=1 to save.
 */

use Drupal\Core\Database\Database;

$nid = 253424;
$oldTitle = '000051805';
$sourceTitle = '000063348';
$db = Database::getConnection();
$sourceDb = Database::getConnection('default', 'external');
$source = $sourceDb->select('node', 'n')
  ->fields('n', ['title'])
  ->condition('n.nid', $nid)
  ->condition('n.type', 'laptop')
  ->execute()->fetchField();
$sourceSerial = $sourceDb->select('field_data_field_serial_number', 's')
  ->fields('s', ['field_serial_number_value'])
  ->condition('s.entity_id', $nid)
  ->execute()->fetchField();
$node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
if (!$node || $node->bundle() !== 'dootronic' || $node->label() !== $oldTitle
  || $source !== $sourceTitle
  || $node->get('field_serial_number')->value !== $sourceSerial) {
  throw new RuntimeException('Source and destination records do not match the expected device.');
}

$collision = $db->select('node_field_data', 'n')
  ->condition('n.type', 'dootronic')
  ->condition('n.title', $sourceTitle)
  ->countQuery()->execute()->fetchField();
if ($collision) {
  throw new RuntimeException('The original label is already assigned in V3.');
}

$pathStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$aliases = [];
foreach ([$nid, 205778] as $deviceNid) {
  $aliases[$deviceNid] = array_map(
    static fn($alias) => ['id' => $alias->id(), 'path' => $alias->getPath(), 'alias' => $alias->getAlias()],
    $pathStorage->loadByProperties(['path' => '/node/' . $deviceNid])
  );
}

echo json_encode([
  'nid' => $nid,
  'v2_title' => $source,
  'v3_title' => $node->label(),
  'serial' => $sourceSerial,
  'aliases' => $aliases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (getenv('LABDOO_APPLY_REPAIR') !== '1') {
  echo "Dry run: no changes made.\n";
  return;
}

$backup = '/tmp/labdoo-dootronic-253424-before-' . gmdate('YmdHis') . '.json';
file_put_contents($backup, json_encode([
  'node' => $node->toArray(),
  'aliases' => $aliases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
chmod($backup, 0600);
echo "Backup: {$backup}\n";

$node->setTitle($sourceTitle);
$node->save();
echo "Saved title: {$node->label()}\n";
