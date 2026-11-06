<?php

if (PHP_SAPI !== 'cli') { throw new RuntimeException('CLI only'); }
umask(0077);
$lab = __DIR__;
while (basename($lab) !== '.e2e-lab' && dirname($lab) !== $lab) { $lab = dirname($lab); }
if (basename($lab) !== '.e2e-lab') { throw new RuntimeException('Missing isolated lab root'); }
$connection = \Drupal\Core\Database\Database::getConnection('default', 'external');
$connection->query('SELECT 1');
$options = $connection->getConnectionOptions();
$options['init_commands']['labdoo_e2e_readonly'] = 'SET SESSION TRANSACTION READ ONLY';
$path = $lab . '/state/external-connection.json';
file_put_contents($path, json_encode($options, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
chmod($path, 0600);
echo 'Legacy source connection available; E2E sessions will be read-only.' . PHP_EOL;
