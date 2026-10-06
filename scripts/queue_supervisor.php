<?php

/**
 * Read-only snapshot of every Drupal database queue.
 *
 * Run with: drush scr scripts/queue_supervisor.php
 *
 * Emits one JSON document so it can be captured by cron, systemd, or a log
 * collector. This script never claims or deletes queue items.
 */

$database = \Drupal::database();
$now = time();

$rows = $database->query(
  'SELECT name, COUNT(*) AS item_count, MIN(created) AS oldest_created,
    SUM(CASE WHEN expire > :now THEN 1 ELSE 0 END) AS active_leases,
    MAX(created) AS newest_created
   FROM {queue}
   GROUP BY name
   ORDER BY item_count DESC, name ASC',
  [':now' => $now]
)->fetchAllAssoc('name');

$queues = [];
foreach ($rows as $name => $row) {
  $oldestCreated = (int) $row->oldest_created;
  $queues[] = [
    'name' => $name,
    'items' => (int) $row->item_count,
    'oldest_created' => $oldestCreated,
    'oldest_age_seconds' => max(0, $now - $oldestCreated),
    'newest_created' => (int) $row->newest_created,
    'active_leases' => (int) $row->active_leases,
  ];
}

$report = [
  'captured_at' => gmdate('c', $now),
  'source' => 'drupal_database_queue',
  'queue_count' => count($queues),
  'total_items' => array_sum(array_column($queues, 'items')),
  'queues' => $queues,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
