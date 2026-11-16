<?php
/**
 * Emits queue metrics to CloudWatch.
 * Usage: drush scr scripts/queue_metrics.php
 */

$queues = [
  'labdoo_common_cache_invalidation',
  'labdoo_dootrip_geocoding',
  'labdoo_hub_recompute',
  'labdoo_dootronics_recompute',
  'labdoo_edoovillage_recompute',
];

$database = \Drupal::database();
$timestamp = time();

foreach ($queues as $queue_name) {
  // Count items
  $count = $database->query("SELECT count(*) FROM {queue} WHERE name = :name", [':name' => $queue_name])->fetchField();

  // Max age (created timestamp)
  $max_age = $database->query("SELECT (UNIX_TIMESTAMP() - created) FROM {queue} WHERE name = :name ORDER BY created ASC LIMIT 1", [':name' => $queue_name])->fetchField() ?: 0;

  // Active leases (items being processed)
  $leases = $database->query("SELECT count(*) FROM {queue} WHERE name = :name AND expire > UNIX_TIMESTAMP()", [':name' => $queue_name])->fetchField();

  echo "Queue: $queue_name | Items: $count | MaxAge: {$max_age}s | Leases: $leases\n";

  // CloudWatch PutMetricData
  // This script should run on an EC2 instance with an IAM Role allowing cloudwatch:PutMetricData
  $namespace = "Labdoo/Queues";

  $metrics = [
    ['Name' => 'BacklogSize', 'Value' => (float)$count],
    ['Name' => 'MaxAge', 'Value' => (float)$max_age],
    ['Name' => 'ActiveLeases', 'Value' => (float)$leases],
  ];

  foreach ($metrics as $metric) {
    $cmd = sprintf(
      "aws cloudwatch put-metric-data --namespace %s --metric-name %s --dimensions QueueName=%s --value %f --region us-east-1",
      escapeshellarg($namespace),
      escapeshellarg($metric['Name']),
      escapeshellarg($queue_name),
      $metric['Value']
    );
    // Only execute if aws cli is present
    exec("which aws && " . $cmd);
  }
}
