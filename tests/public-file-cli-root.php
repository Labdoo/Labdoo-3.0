<?php

/**
 * @file
 * Real standalone boot from APP and WEB, without changing file or node rows.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;

$db = Database::getConnection();
if (!Settings::get('labdoo_e2e') || !str_starts_with($db->getConnectionOptions()['database'], 'labdoo_e2e_')) {
  throw new RuntimeException('An isolated E2E database is required.');
}
$web = realpath(DRUPAL_ROOT);
$app = dirname($web);
$scripts = dirname(__DIR__) . '/scripts';
$private = sys_get_temp_dir() . '/public-cli-root-' . bin2hex(random_bytes(6));
mkdir($private, 0700);
$from = $private . '/empty-backup.jsonl';
file_put_contents($from, json_encode(['kind' => 'header', 'schema' => 1, 'database' => $db->getConnectionOptions()['database']]) . "\n");
chmod($from, 0600);
$counters = static fn(): array => $db->query('SELECT (SELECT COUNT(*) FROM {file_managed}) AS files, (SELECT COUNT(*) FROM {node}) AS nodes, (SELECT MAX(nid) FROM {node}) AS nid, (SELECT COUNT(*) FROM {node_revision}) AS revisions, (SELECT MAX(vid) FROM {node_revision}) AS vid')->fetchAssoc();
$before = $counters();
$absentFid = (int) $db->query('SELECT MAX(fid) FROM {file_managed}')->fetchField() + 1000000;
$passed = [];
try {
  foreach (['APP' => $app, 'WEB' => $web] as $label => $cwd) {
    // Relative paths deliberately start at the caller's APP or WEB directory.
    $relativePrivate = str_repeat('../', count(explode('/', trim($cwd, '/')))) . ltrim($private, '/');
    foreach (['metadata', 'aliases'] as $kind) {
      $manifest = $relativePrivate . '/' . strtolower($label) . '-manifest.json';
      $command = [PHP_BINARY, $scripts . '/public-file-uri-' . ($kind === 'metadata' ? 'parity' : 'aliases') . '.php', '--root=' . $web, '--uri=http://127.0.0.1:18381'];
      $command = array_merge($command, $kind === 'metadata' ? ['--fids=' . $absentFid, '--manifest-path=' . $manifest] : ['--from=' . $relativePrivate . '/empty-backup.jsonl']);
      $pipes = [];
      $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
      fclose($pipes[0]);
      $stdout = stream_get_contents($pipes[1]);
      $stderr = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $exit = proc_close($process);
      if ($exit !== 0) {
        throw new RuntimeException("$label $kind standalone failed: " . $stderr);
      }
      $result = json_decode($stdout, TRUE, 512, JSON_THROW_ON_ERROR);
      if ($result['mode'] !== 'dry-run' || $result['summary']['scanned'] !== 0) {
        throw new LogicException('Unexpected standalone dry-run result.');
      }
      if ($kind === 'metadata') {
        $output = $private . '/' . strtolower($label) . '-manifest.json';
        if (!is_file($output) || (fileperms($output) & 0777) !== 0600) {
          throw new LogicException('Caller-relative manifest path or private permissions changed.');
        }
      }
      $passed[] = "$label $kind real standalone boot and relative input/output";
    }
  }
  if ($before !== $counters()) {
    throw new LogicException('Standalone read-only probes changed entity counters.');
  }
  $passed[] = 'file/node/revision counters unchanged';
  echo json_encode(['passed' => count($passed), 'cases' => $passed], JSON_PRETTY_PRINT) . PHP_EOL;
}
finally {
  foreach (glob($private . '/*') as $path) {
    unlink($path);
  }
  rmdir($private);
}
