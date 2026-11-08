<?php

// Run only in the existing, isolated integration laboratory.
require __DIR__ . '/assert-isolated.php';

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\labdoo_migrate\Commands\DootronicAddressCommands;
use Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$state = Settings::get('labdoo_e2e_state');
$options = json_decode(file_get_contents($state . '/v2-readonly-connection.json'), TRUE, 512, JSON_THROW_ON_ERROR);
if ($options['database'] !== 'labdoo_e2e_v2_20261107' || $options['username'] !== 'labdoo_e2e_v2_read') {
  throw new RuntimeException('Use the isolated, SELECT-only V2 fixture database.');
}
Database::removeConnection('external');
Database::addConnectionInfo('external', 'default', $options);
$source = Database::getConnection('default', 'external');
if (!(int) $source->query('SELECT @@session.tx_read_only')->fetchField()) {
  throw new RuntimeException('Source must be read-only.');
}
$check = static function ($ok, $message) {
  if (!$ok) {
    throw new RuntimeException($message);
  }
  echo 'PASS: ' . $message . PHP_EOL;
};
$snapshot = static fn() => Database::getConnection()->query('SELECT (SELECT COUNT(*) FROM node) AS nodes,(SELECT MAX(nid) FROM node) AS nid,(SELECT COUNT(*) FROM node_revision) AS revisions,(SELECT MAX(vid) FROM node_revision) AS vid')->fetchAssoc();
$before = $snapshot();
$storage = \Drupal::entityTypeManager()->getStorage('node');
$write = new ReflectionMethod($storage, 'saveToDedicatedTables');
$write->setAccessible(TRUE);
$samples = json_decode(file_get_contents($state . '/legacy-address-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR)['samples'];
$ids = [];
foreach ($samples as $sample) {
  $node = $storage->load($sample['source_id']);
  if (!$node || $node->bundle() !== 'dootronic') {
    throw new RuntimeException('Missing existing migrated source node.');
  }
  $node->set('field_address', NULL);
  $write->invoke($storage, $node, TRUE, ['field_address']);
  $ids[] = $node->id();
}
$storage->resetCache($ids);
$command = new DootronicAddressCommands(new ExternalConnectionManager(), \Drupal::entityTypeManager());
$command->setInput(new ArrayInput([]));
$command->setOutput(new BufferedOutput());
$commandOptions = ['apply' => FALSE, 'nids' => implode(',', $ids), 'limit' => 0, 'backup-path' => ''];
$command->backfill($commandOptions);
$check($storage->load(reset($ids))->get('field_address')->isEmpty(), 'dry run does not populate addresses');
$commandOptions['apply'] = TRUE;
$commandOptions['backup-path'] = $state . '/address-backfill-' . uniqid() . '.jsonl';
$command->backfill($commandOptions);
$check((fileperms($commandOptions['backup-path']) & 0777) === 0600, 'backup is private');
foreach ($samples as $sample) {
  $node = $storage->load($sample['source_id']);
  $actual = $node->get('field_address')->first()?->getValue() ?? [];
  foreach ($sample['expected'] as $component => $expected) {
    $check(($actual[$component] ?? '') === $expected, $sample['case'] . ': ' . $component . ' matches independent V2 oracle');
  }
}
$commandOptions['backup-path'] = $state . '/address-backfill-repeat-' . uniqid() . '.jsonl';
$command->backfill($commandOptions);
$check(filesize($commandOptions['backup-path']) === 0, 'second apply preserves populated addresses');
$check($snapshot() === $before, 'node and revision counts and maximum IDs are unchanged');
