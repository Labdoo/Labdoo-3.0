<?php

/**
 * @file
 * Isolated regression of old public URLs and guarded alias rollback.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\file\Entity\File;

if (!Settings::get('labdoo_e2e') || !str_starts_with(Database::getConnection()->getConnectionOptions()['database'], 'labdoo_e2e_')) {
  throw new RuntimeException('An isolated E2E database is required.');
}
require_once dirname(__DIR__) . '/scripts/public-file-uri-aliases.php';
$db = \Drupal::database();
$root = \Drupal::service('file_system')->realpath('public://');
$nonce = 'e2e-file-alias-' . bin2hex(random_bytes(5));
$directory = $root . '/' . $nonce;
$oldDirectory = $root . '/content/image/migrate/blog/' . $nonce;
$private = sys_get_temp_dir() . '/' . $nonce;
mkdir($directory, 0770);
mkdir($private, 0700);
$files = [];
$passed = [];
$placeholder = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
$chunk = static fn(string $type, string $value): string => pack('N', strlen($value)) . $type . $value . pack('N', crc32($type . $value));
$png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 4, 3, 8, 6, 0, 0, 0)) . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\x10\x80\x20\xff", 4), 3))) . $chunk('IEND', '');
$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new LogicException($message);
  }
};
$nodeCounters = static fn(): array => $db->query('SELECT (SELECT COUNT(*) FROM {node}) AS nodes, (SELECT MAX(nid) FROM {node}) AS max_nid, (SELECT COUNT(*) FROM {node_revision}) AS revisions, (SELECT MAX(vid) FROM {node_revision}) AS max_vid')->fetchAssoc();
$beforeNodes = $nodeCounters();
$fixture = static function (string $name, bool $existingPlaceholder = FALSE, bool $ownedOriginal = FALSE) use ($db, $root, $nonce, $directory, $oldDirectory, $private, $png, $placeholder, &$files): array {
  file_put_contents($directory . '/' . $name, $png);
  if ($existingPlaceholder) {
    if (!is_dir($oldDirectory)) {
      mkdir($oldDirectory, 0770, TRUE);
    }
    file_put_contents($oldDirectory . '/' . $name, $placeholder);
    chmod($oldDirectory . '/' . $name, 0640);
    touch($oldDirectory . '/' . $name, 1700000000);
  }
  $file = File::create(['filename' => $name, 'uri' => 'public://content/image/migrate/blog/' . $nonce . '/' . $name, 'filemime' => 'image/png', 'filesize' => 68, 'status' => 1]);
  $file->save();
  $fid = (int) $file->id();
  $files[] = $fid;
  $source = ['fid' => 900001, 'uuid' => 'source-' . $name, 'filename' => $name, 'uri' => 'public://' . $nonce . '/' . $name, 'filemime' => 'image/png', 'filesize' => strlen($png)];
  $ownerId = NULL;
  if ($ownedOriginal) {
    $owner = File::create(['filename' => $name, 'uri' => str_replace('/' . $name, '//' . $name, $source['uri']), 'filemime' => 'image/png', 'filesize' => strlen($png), 'status' => 1]);
    $owner->save();
    $ownerId = (int) $owner->id();
    $files[] = $ownerId;
  }
  $repair = new LabdooPublicFileUriParity($db, NULL, $root, $root, [$source]);
  $plan = $repair->plan([$fid]);
  $backup = $private . '/metadata-' . $name . '.jsonl';
  $repair->apply($plan, $backup);
  return [$fid, $backup, $plan, $repair, $ownerId];
};
$aliases = new LabdooPublicFileUriAliases($db, $root);

try {
  [$fid, $metadata] = $fixture('missing.png');
  $plan = $aliases->plan($metadata);
  $assert($plan['summary']['repairable'] === 1 && !is_link($oldDirectory . '/missing.png'), 'Alias dry-run must not create files or symlinks.');
  $passed[] = 'alias dry-run does not write';
  $beforeMetadata = $db->select('file_managed', 'f')->fields('f')->condition('fid', $fid)->execute()->fetchAssoc();
  $aliasBackup = $private . '/alias-missing.jsonl';
  $result = $aliases->apply($plan, $aliasBackup);
  $oldPath = $oldDirectory . '/missing.png';
  $assert($result['created'] === 1 && is_link($oldPath) && !str_starts_with(readlink($oldPath), '/'), 'A new alias must be relative.');
  $assert(file_get_contents($oldPath) === $png && realpath($oldPath) === realpath($directory . '/missing.png'), 'Reading the old URL path must return the exact original bytes.');
  $assert((fileperms($aliasBackup) & 0777) === 0600, 'Alias backup must be private.');
  $assert($db->select('file_managed', 'f')->fields('f')->condition('fid', $fid)->execute()->fetchAssoc() === $beforeMetadata, 'Filesystem phase must not change file metadata.');
  $passed[] = 'old path reads original bytes without DB writes';
  $http = new \GuzzleHttp\Client(['base_uri' => \Drupal::request()->getSchemeAndHttpHost(), 'proxy' => '', 'connect_timeout' => 2, 'timeout' => 5]);
  foreach (['/sites/default/files/content/image/migrate/blog/' . $nonce . '/missing.png', '/sites/default/files/' . $nonce . '/missing.png'] as $url) {
    $response = $http->get($url);
    $assert($response->getStatusCode() === 200 && (string) $response->getBody() === $png, 'Both old and canonical public URLs must serve the exact image over local HTTP.');
  }
  $passed[] = 'old and canonical URLs return HTTP 200 with identical bytes';

  $again = $aliases->plan($metadata);
  $assert($again['summary']['repairable'] === 0 && $again['summary']['already_alias'] === 1, 'Alias creation must be idempotent.');
  $passed[] = 'alias idempotence';
  $rollback = $aliases->rollback($aliasBackup);
  $assert($rollback['restored'] === 1 && !is_link($oldPath) && is_file($directory . '/missing.png'), 'Rollback must remove only the alias, retaining original bytes.');
  $passed[] = 'missing path rollback retains original';

  [, $metadata, , $repair] = $fixture('placeholder.png', TRUE);
  $plan = $aliases->plan($metadata);
  $assert($plan['records'][0]['before']['kind'] === 'placeholder', 'Known placeholder must be explicitly recorded.');
  $aliasBackup = $private . '/alias-placeholder.jsonl';
  $aliases->apply($plan, $aliasBackup);
  $assert(is_link($oldDirectory . '/placeholder.png') && file_get_contents($oldDirectory . '/placeholder.png') === $png, 'Only the known placeholder should be replaced.');
  $aliases->rollback($aliasBackup);
  clearstatcache();
  $assert(!is_link($oldDirectory . '/placeholder.png') && file_get_contents($oldDirectory . '/placeholder.png') === $placeholder, 'Rollback must restore exact placeholder bytes.');
  $assert((fileperms($oldDirectory . '/placeholder.png') & 0777) === 0640 && filemtime($oldDirectory . '/placeholder.png') === 1700000000, 'Placeholder permissions and mtime must be restored.');
  $repair->rollback($metadata);
  $passed[] = 'placeholder bytes and metadata restored';

  [, $metadata] = $fixture('protected.png');
  file_put_contents($oldDirectory . '/protected.png', 'REAL EDITOR FILE');
  $plan = $aliases->plan($metadata);
  $assert($plan['summary']['protected_existing'] === 1 && !$plan['records'] && file_get_contents($oldDirectory . '/protected.png') === 'REAL EDITOR FILE', 'An existing real file must never be replaced.');
  $passed[] = 'existing real files protected';

  [$fid, $metadata] = $fixture('racing.png');
  $plan = $aliases->plan($metadata);
  file_put_contents($oldDirectory . '/racing.png', 'CONCURRENT EDIT');
  $failed = FALSE;
  try {
    $aliases->apply($plan, $private . '/alias-racing.jsonl');
  }
  catch (RuntimeException) {
    $failed = TRUE;
  }
  $assert($failed && file_get_contents($oldDirectory . '/racing.png') === 'CONCURRENT EDIT' && !is_link($oldDirectory . '/racing.png'), 'Concurrent files must win over alias creation.');
  $passed[] = 'concurrent file creation preserved';

  [, $metadata] = $fixture('changed-link.png');
  $plan = $aliases->plan($metadata);
  $aliasBackup = $private . '/alias-changed.jsonl';
  $aliases->apply($plan, $aliasBackup);
  $path = $oldDirectory . '/changed-link.png';
  $replacement = './' . readlink($path);
  unlink($path);
  symlink($replacement, $path);
  $rollback = $aliases->rollback($aliasBackup);
  $assert($rollback['restored'] === 0 && $rollback['concurrent_or_unconfirmed_conflicts'] === 1 && readlink($path) === $replacement, 'Rollback must refuse a symlink changed after repair.');
  $passed[] = 'rollback only unlinks owned alias';

  $originalLines = file($metadata, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach (['public://private/forbidden.png', 'public://../forbidden.png'] as $index => $unsafeUri) {
    $copy = [];
    foreach ($originalLines as $line) {
      $entry = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
      if ($entry['kind'] === 'file') {
        $entry['record']['file']['uri'] = $unsafeUri;
      }
      $copy[] = json_encode($entry, JSON_THROW_ON_ERROR);
    }
    $input = $private . '/unsafe-' . $index . '.jsonl';
    file_put_contents($input, implode("\n", $copy) . "\n");
    $negative = $aliases->plan($input);
    $assert($negative['summary']['unsafe_or_changed_target'] === 1 && !$negative['records'], 'Private or parent-traversing alias paths must be rejected.');
  }
  $passed[] = 'private and traversal paths rejected';

  symlink($private, $directory . '/escape');
  $copy = [];
  foreach ($originalLines as $line) {
    $entry = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
    if ($entry['kind'] === 'file') {
      $entry['record']['file']['uri'] = 'public://' . $nonce . '/escape/forbidden.png';
    }
    $copy[] = json_encode($entry, JSON_THROW_ON_ERROR);
  }
  $input = $private . '/escaping-parent.jsonl';
  file_put_contents($input, implode("\n", $copy) . "\n");
  $negative = $aliases->plan($input);
  $assert($negative['summary']['unsafe_or_changed_target'] === 1 && !$negative['records'] && !file_exists($private . '/forbidden.png'), 'Existing parent symlinks must not escape public files.');
  $passed[] = 'escaping parent symlinks rejected';
  [, $crashMetadata] = $fixture('crash-placeholder.png', TRUE);
  $crashPlan = $aliases->plan($crashMetadata);
  $intent = $crashPlan['records'][0];
  $intent['hold_relative'] = dirname($intent['old_relative']) . '/.labdoo-placeholder-' . $intent['operation'];
  $crashBackup = $private . '/alias-crash.jsonl';
  file_put_contents($crashBackup, json_encode(['kind' => 'alias_header', 'schema' => 1, 'database' => $db->getConnectionOptions()['database'], 'root' => realpath($root)]) . "\n" . json_encode(['kind' => 'alias_intent', 'record' => $intent]) . "\n");
  $hold = $oldDirectory . '/.labdoo-placeholder-' . $intent['operation'];
  rename($oldDirectory . '/crash-placeholder.png', $hold);
  $recovered = $aliases->rollback($crashBackup);
  $assert($recovered['restored'] === 1 && file_get_contents($oldDirectory . '/crash-placeholder.png') === $placeholder && !file_exists($hold), 'A crash before link creation must restore the preserved placeholder safely.');
  $passed[] = 'interrupted placeholder replacement recovered';

  [$ownedFid, $ownedMetadata, $ownedPlan] = $fixture('owned-source.png', FALSE, TRUE);
  $assert($ownedPlan['summary']['kept_uri_requires_hardlink'] === 1, 'Normalized existing ownership must require an independent hardlink entry.');
  $hardPlan = $aliases->plan($ownedMetadata);
  $assert($hardPlan['records'][0]['mode'] === 'hardlink', 'Owned canonical paths must not receive dependent symlinks.');
  $hardBackup = $private . '/hardlink.jsonl';
  $aliases->apply($hardPlan, $hardBackup);
  $ownedPath = $oldDirectory . '/owned-source.png';
  $canonicalPath = $directory . '/owned-source.png';
  $assert(!is_link($ownedPath) && is_file($ownedPath) && lstat($ownedPath)['ino'] === lstat($canonicalPath)['ino'] && lstat($ownedPath)['nlink'] >= 2, 'Hardlink must preserve bytes without allocating another full copy.');
  $assert($db->select('file_managed', 'f')->fields('f', ['uri'])->condition('fid', $ownedFid)->execute()->fetchField() === $ownedPlan['records'][0]['file']['uri'], 'The repaired FID must keep its own legacy URI.');
  foreach (['/sites/default/files/content/image/migrate/blog/' . $nonce . '/owned-source.png', '/sites/default/files/' . $nonce . '/owned-source.png'] as $url) {
    $response = $http->get($url);
    $assert($response->getStatusCode() === 200 && (string) $response->getBody() === $png, 'Hardlinked old and canonical URLs must both serve the original over HTTP.');
  }
  $again = $aliases->plan($ownedMetadata);
  $assert($again['summary']['already_alias'] === 1, 'Existing independent hardlinks must be idempotent.');
  $restored = $aliases->rollback($hardBackup);
  $assert($restored['restored'] === 1 && !file_exists($ownedPath) && is_file($canonicalPath), 'Hardlink rollback must remove only our entry.');
  $passed[] = 'normalized owner uses independent hardlink with guarded rollback';

  [, $deletedMetadata, , , $deletedOwner] = $fixture('deleted-owner.png', FALSE, TRUE);
  $deletedPlan = $aliases->plan($deletedMetadata);
  $deletedBackup = $private . '/deleted-owner.jsonl';
  $aliases->apply($deletedPlan, $deletedBackup);
  \Drupal::entityTypeManager()->getStorage('file')->resetCache([$deletedOwner]);
  File::load($deletedOwner)->delete();
  clearstatcache();
  $remaining = $oldDirectory . '/deleted-owner.png';
  $assert(!file_exists($directory . '/deleted-owner.png') && is_file($remaining) && file_get_contents($remaining) === $png && lstat($remaining)['nlink'] === 1, 'Deleting the canonical FID must not delete another FID’s hardlinked bytes.');
  $refused = $aliases->rollback($deletedBackup);
  $assert($refused['restored'] === 0 && $refused['concurrent_or_unconfirmed_conflicts'] === 1 && file_get_contents($remaining) === $png, 'Rollback must not remove the last surviving hardlink copy.');
  $passed[] = 'canonical deletion does not lose the other managed file';

  $assert($nodeCounters() === $beforeNodes, 'No node IDs or revisions may be consumed.');
  $passed[] = 'node IDs and revisions unchanged';
}
finally {
  foreach (array_reverse($files) as $fid) {
    \Drupal::entityTypeManager()->getStorage('file')->resetCache([$fid]);
    if ($entity = File::load($fid)) {
      $entity->delete();
    }
  }
  $remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) {
      if (file_exists($path) || is_link($path)) {
        unlink($path);
      }
      return;
    }
    foreach (scandir($path) ?: [] as $name) {
      if ($name !== '.' && $name !== '..') {
        $remove($path . '/' . $name);
      }
    }
    rmdir($path);
  };
  $remove($oldDirectory);
  $remove($directory);
  $remove($private);
}
echo json_encode(['passed' => count($passed), 'cases' => $passed, 'node_writes' => 0, 'local_http_requests' => 4, 'external_http_requests' => 0], JSON_PRETTY_PRINT) . PHP_EOL;
