<?php

if (PHP_SAPI !== 'cli') { throw new RuntimeException('CLI only'); }
umask(0077);
$lab = __DIR__;
while (basename($lab) !== '.e2e-lab' && dirname($lab) !== $lab) { $lab = dirname($lab); }
if (basename($lab) !== '.e2e-lab') { throw new RuntimeException('Missing isolated lab root'); }
$state = $lab . '/state';
$file = $state . '/database-private.json';
$info = file_exists($file) ? json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR) : [];
$source = \Drupal::database();
$options = $source->getConnectionOptions();
if (!in_array($options['host'] ?? '', ['localhost', '127.0.0.1', '::1'], TRUE)) {
  throw new RuntimeException('Expected a local source database');
}
$info['source'] = $options;
$info['user'] ??= 'labdoo_e2e_20261106';
$info['password'] ??= bin2hex(random_bytes(24));
$info['databases'] = ['baseline' => 'labdoo_e2e_base_20261106', 'integration' => 'labdoo_e2e_int_20261106'];
$info['hashSalt'] ??= bin2hex(random_bytes(32));
file_put_contents($file, json_encode($info, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
chmod($file, 0600);
$pdo = $source->getClientConnection();
foreach ($info['databases'] as $name) {
  if (!preg_match('/^labdoo_e2e_[a-z0-9_]+$/D', $name)) { throw new RuntimeException('Unsafe target database'); }
  $source->query('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
foreach (['localhost', '127.0.0.1'] as $host) {
  $account = $pdo->quote($info['user']) . '@' . $pdo->quote($host);
  $source->query('CREATE USER IF NOT EXISTS ' . $account . ' IDENTIFIED BY ' . $pdo->quote($info['password']));
  foreach ($info['databases'] as $name) {
    $source->query('GRANT ALL PRIVILEGES ON `' . $name . '`.* TO ' . $account);
  }
}
$tables = $source->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
$info['excludeData'] = [];
foreach ($tables as [$name, $type]) {
  if ($type !== 'BASE TABLE') { throw new RuntimeException('Review non-table objects before cloning'); }
  if (str_starts_with($name, 'cache_') || in_array($name, ['watchdog', 'sessions', 'batch', 'queue', 'key_value_expire'], TRUE)) {
    $info['excludeData'][] = $name;
  }
}
file_put_contents($file, json_encode($info, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Prepared two isolated databases and a dedicated account without production grants.' . PHP_EOL;
echo 'Data excluded for ' . count($info['excludeData']) . ' cache, session, log and queue tables.' . PHP_EOL;
