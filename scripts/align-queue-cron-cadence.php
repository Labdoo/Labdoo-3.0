<?php

/**
 * Align Ultimate Cron queue schedules with the existing five-minute OS cron cadence.
 *
 * Default read-only. LABDOO_CRON_APPLY=1 requires a new private
 * LABDOO_CRON_BACKUP JSON file outside the document root. No jobs are launched.
 * LABDOO_CRON_RESTORE=/backup.json prepares the inverse change; it requires the
 * current configuration to match that backup's expected result exactly.
 */

$cron_counters = static function ($db): array {
  return [
    'nodes' => $db->query('SELECT COUNT(*) AS count,MAX(nid) AS max FROM {node}')->fetchAssoc(),
    'node_auto_increment' => $db->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:name", [':name' => $db->getPrefix() . 'node'])->fetchField(),
    'revision_auto_increment' => $db->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:name", [':name' => $db->getPrefix() . 'node_revision'])->fetchField(),
    'revisions' => $db->query('SELECT COUNT(*) AS count,MAX(vid) AS max FROM {node_revision}')->fetchAssoc(),
    'queues' => $db->query('SELECT name,COUNT(*) AS count,MAX(item_id) AS max FROM {queue} GROUP BY name ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC),
  ];
};

$apply = getenv('LABDOO_CRON_APPLY') === '1';
$factory = \Drupal::configFactory();
$db = \Drupal::database();
$changes = [];
$restore_path = getenv('LABDOO_CRON_RESTORE');
if ($restore_path) {
  $saved = json_decode(file_get_contents($restore_path), TRUE, 512, JSON_THROW_ON_ERROR);
  foreach ($saved['changes'] as $name => $change) {
    if (!str_starts_with($name, 'ultimate_cron.job.ultimate_cron_queue_')) {
      throw new RuntimeException('Unexpected non-queue configuration in backup.');
    }
    $current = $factory->getEditable($name)->getRawData();
    if ($current !== $change['after']) {
      throw new RuntimeException('Configuration changed since repair; automatic rollback refused.');
    }
    $changes[$name] = ['before' => $current, 'after' => $change['before']];
  }
}
else {
  foreach ($factory->listAll('ultimate_cron.job.ultimate_cron_queue_') as $name) {
    $before = $factory->getEditable($name)->getRawData();
    if (($before['callback'] ?? NULL) !== 'ultimate_cron.queue_worker:queueCallback' || ($before['scheduler']['id'] ?? NULL) !== 'simple' || ($before['scheduler']['configuration']['rules'] ?? NULL) !== ['* * * * *']) {
      continue;
    }
    $after = $before;
    // No +@ skew: it would reintroduce minute offsets between five-minute runs.
    $after['scheduler']['configuration']['rules'] = ['*/5 * * * *'];
    $changes[$name] = ['before' => $before, 'after' => $after];
  }
}
$plan = ['mode' => $apply ? 'apply' : 'dry-run', 'direction' => $restore_path ? 'restore' : 'align', 'count' => count($changes), 'changes' => []];
foreach ($changes as $name => $change) {
  $plan['changes'][] = [
    'config' => $name,
    'enabled' => $change['before']['status'],
    'before_rule' => $change['before']['scheduler']['configuration']['rules'],
    'after_rule' => $change['after']['scheduler']['configuration']['rules'],
    'launcher_unchanged' => $change['before']['launcher'] === $change['after']['launcher'],
    'catch_up_unchanged' => ($change['before']['scheduler']['configuration']['catch_up'] ?? NULL) === ($change['after']['scheduler']['configuration']['catch_up'] ?? NULL),
  ];
}
if (!$apply || !$changes) {
  echo json_encode($plan, JSON_PRETTY_PRINT) . PHP_EOL;
  return;
}
$path = getenv('LABDOO_CRON_BACKUP');
$parent = $path ? realpath(dirname($path)) : FALSE;
if (!$parent || !str_starts_with($path, '/') || str_starts_with($parent . '/', realpath(\Drupal::root()) . '/')) {
  throw new RuntimeException('Apply requires a new private backup outside the document root.');
}
$backup = fopen($path, 'x');
if (!$backup || !chmod($path, 0600)) {
  throw new RuntimeException('Cannot create a private cron configuration backup.');
}
$counters_before = $cron_counters($db);
$snapshot = ['created' => gmdate('c'), 'plan' => $plan, 'changes' => $changes, 'counters_before' => $counters_before];
if (fwrite($backup, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)) === FALSE || !fflush($backup) || !fsync($backup)) {
  throw new RuntimeException('Cannot persist configuration backup before applying.');
}
fclose($backup);
$transaction = $db->startTransaction();
try {
  foreach ($changes as $name => $change) {
    $config = $factory->getEditable($name);
    if ($config->getRawData() !== $change['before']) {
      throw new RuntimeException('A cron job configuration changed concurrently.');
    }
    // Change only this path: status, callbacks, launchers and catch_up stay intact.
    $config->set('scheduler.configuration.rules', $change['after']['scheduler']['configuration']['rules'])->save();
    if ($factory->getEditable($name)->getRawData() !== $change['after']) {
      throw new RuntimeException('Unexpected change beyond the queue scheduling rule.');
    }
  }
  if ($cron_counters($db) !== $counters_before) {
    throw new RuntimeException('Node, revision or queue counters changed; configuration transaction aborted.');
  }
  unset($transaction);
  \Drupal::entityTypeManager()->getStorage('ultimate_cron_job')->resetCache();
  $plan['counters_unchanged'] = TRUE;
  $plan['no_callbacks_launched'] = TRUE;
  echo json_encode($plan, JSON_PRETTY_PRINT) . PHP_EOL;
}
catch (\Throwable $exception) {
  if (isset($transaction)) {
    $transaction->rollBack();
  }
  foreach (array_keys($changes) as $name) {
    $factory->reset($name);
  }
  throw $exception;
}
