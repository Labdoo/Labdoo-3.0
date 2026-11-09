<?php

/**
 * @file
 * Isolated DB regression for public-file URI repair and guarded rollback.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;

if (!Settings::get('labdoo_e2e') || !str_starts_with(Database::getConnection()->getConnectionOptions()['database'], 'labdoo_e2e_')) {
  throw new RuntimeException('An isolated E2E database is required.');
}
require_once dirname(__DIR__) . '/scripts/public-file-uri-parity.php';

$db = \Drupal::database();
$public = \Drupal::service('file_system')->realpath('public://');
$nonce = 'e2e-uri-parity-' . bin2hex(random_bytes(6));
$fixtureRoot = $public . '/' . $nonce;
$private = sys_get_temp_dir() . '/' . $nonce;
mkdir($fixtureRoot, 0770);
mkdir($private, 0700);
$files = [];
$media = [];
$passed = [];
$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new LogicException($message);
  }
};
$nodeCounters = static fn(): array => $db->query('SELECT (SELECT COUNT(*) FROM {node}) AS nodes, (SELECT MAX(nid) FROM {node}) AS max_nid, (SELECT COUNT(*) FROM {node_revision}) AS revisions, (SELECT MAX(vid) FROM {node_revision}) AS max_vid')->fetchAssoc();
$beforeNodes = $nodeCounters();
$chunk = static fn(string $type, string $value): string => pack('N', strlen($value)) . $type . $value . pack('N', crc32($type . $value));
$png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 4, 3, 8, 6, 0, 0, 0)) . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\x10\x80\x20\xff", 4), 3))) . $chunk('IEND', '');
$source = static function (string $name, string $subdirectory = '') use ($fixtureRoot, $nonce, $png): array {
  $folder = $fixtureRoot . ($subdirectory ? '/' . $subdirectory : '');
  if (!is_dir($folder)) {
    mkdir($folder, 0770, TRUE);
  }
  file_put_contents($folder . '/' . $name, $png);
  return ['fid' => 900001, 'uuid' => 'source-' . $name, 'filename' => $name, 'uri' => 'public://' . $nonce . ($subdirectory ? '/' . $subdirectory : '') . '/' . $name, 'filemime' => 'image/png', 'filesize' => strlen($png)];
};
$create = static function (string $name, int $size = 68, string $scheme = 'public://content/image/migrate/blog/') use (&$files, $nonce): File {
  $file = File::create(['filename' => $name, 'uri' => $scheme . $nonce . '/' . $name, 'filemime' => 'image/png', 'filesize' => $size, 'status' => 1]);
  $file->save();
  $files[] = (int) $file->id();
  return $file;
};
$runner = static fn(array $sources): LabdooPublicFileUriParity => new LabdooPublicFileUriParity($db, NULL, $public, $public, $sources);

try {
  $original = $source('original.png');
  $file = $create('original.png');
  $fid = (int) $file->id();
  $beforeFile = $db->select('file_managed', 'f')->fields('f')->condition('fid', $fid)->execute()->fetchAssoc();
  $item = Media::create(['bundle' => 'image', 'name' => $nonce, 'field_media_image' => ['target_id' => $fid, 'width' => 2, 'height' => 2]]);
  $item->save();
  $media[] = (int) $item->id();
  $historicalRevision = (int) $item->getRevisionId();
  $item->setNewRevision(TRUE);
  $item->set('field_media_image', ['target_id' => $fid, 'width' => 1, 'height' => 1]);
  $item->save();
  $currentRevision = (int) $item->getRevisionId();
  $repair = $runner([$original]);
  $plan = $repair->plan([$fid]);
  $assert($plan['summary']['repairable'] === 1 && $plan['summary']['dimension_rows'] >= 2, 'Dry-run must find the original and current/revision dimensions.');
  $assert($db->select('file_managed', 'f')->fields('f', ['uri'])->condition('fid', $fid)->execute()->fetchField() === $beforeFile['uri'], 'Dry-run must not change the file URI.');
  $passed[] = 'dry-run leaves DB unchanged';

  $backup = $private . '/repair.jsonl';
  $result = $repair->apply($plan, $backup);
  $assert($result['applied'] === 1 && (fileperms($backup) & 0777) === 0600, 'One repair requires a private backup.');
  $after = $db->select('file_managed', 'f')->fields('f')->condition('fid', $fid)->execute()->fetchAssoc();
  $assert($after['uri'] === $original['uri'] && (int) $after['filesize'] === strlen($png) && $after['filemime'] === 'image/png', 'File URI and placeholder metadata must point to the actual original.');
  foreach (['fid', 'uuid', 'uid', 'filename', 'status', 'created', 'changed'] as $key) {
    $assert($after[$key] === $beforeFile[$key], 'Repair must preserve ' . $key);
  }
  foreach (['media__field_media_image', 'media_revision__field_media_image'] as $table) {
    $row = $db->select($table, 't')->fields('t', ['field_media_image_width', 'field_media_image_height'])->condition('entity_id', $item->id())->condition('revision_id', $currentRevision)->execute()->fetchAssoc();
    $assert((int) $row['field_media_image_width'] === 4 && (int) $row['field_media_image_height'] === 3, 'Both current image tables must have actual dimensions.');
  }
  $old = $db->select('media_revision__field_media_image', 't')->fields('t', ['field_media_image_width', 'field_media_image_height'])->condition('entity_id', $item->id())->condition('revision_id', $historicalRevision)->execute()->fetchAssoc();
  $assert((int) $old['field_media_image_width'] === 2 && (int) $old['field_media_image_height'] === 2, 'Historical revision dimensions must remain untouched.');
  $passed[] = 'apply preserves identifiers and repairs current image metadata';

  $again = $runner([$original])->plan([$fid]);
  $assert($again['summary']['repairable'] === 0 && $again['summary']['already_present'] === 1, 'A repeated repair must be idempotent.');
  $passed[] = 'idempotent';

  $restored = $repair->rollback($backup);
  $assert($restored['restored'] === 1, 'Rollback must restore one file.');
  $restoredFile = $db->select('file_managed', 'f')->fields('f')->condition('fid', $fid)->execute()->fetchAssoc();
  $assert($restoredFile === $beforeFile, 'Rollback must restore the exact file before-image.');
  foreach (['media__field_media_image', 'media_revision__field_media_image'] as $table) {
    $width = $db->select($table, 't')->fields('t', ['field_media_image_width'])->condition('entity_id', $item->id())->condition('revision_id', $currentRevision)->execute()->fetchField();
    $assert((int) $width === 1, 'Rollback must restore current image dimensions.');
  }
  $passed[] = 'rollback restores exact metadata';

  $ambiguousA = $source('ambiguous.png', 'a');
  $ambiguousB = $source('ambiguous.png', 'b');
  $ambiguousB['fid'] = 900002;
  $ambiguousFile = $create('ambiguous.png');
  $negative = $runner([$ambiguousA, $ambiguousB])->plan([(int) $ambiguousFile->id()]);
  $assert(($negative['summary']['ambiguous_source'] ?? 0) === 1 && !$negative['records'], 'Duplicate source names must never be guessed.');
  $passed[] = 'ambiguous sources rejected';

  $sizeOriginal = $source('size.png');
  $sizeFile = $create('size.png', 420);
  $negative = $runner([$sizeOriginal])->plan([(int) $sizeFile->id()]);
  $assert(($negative['summary']['v3_size_conflict'] ?? 0) === 1, 'A genuine non-placeholder size conflict must be rejected.');
  $passed[] = 'non-placeholder size conflict rejected';

  $badSource = $source('d7-size.png');
  $badSource['filesize']++;
  $badSourceFile = $create('d7-size.png');
  $negative = $runner([$badSource])->plan([(int) $badSourceFile->id()]);
  $assert(($negative['summary']['d7_size_conflict'] ?? 0) === 1, 'Source metadata and physical bytes must agree.');
  $passed[] = 'D7 size conflict rejected';

  $badMime = $source('mime.png');
  $badMime['filemime'] = 'image/jpeg';
  $badMimeFile = $create('mime.png');
  $negative = $runner([$badMime])->plan([(int) $badMimeFile->id()]);
  $assert(($negative['summary']['d7_mime_conflict'] ?? 0) === 1, 'Source MIME and physical content must agree.');
  $passed[] = 'D7 MIME conflict rejected';

  $privateOriginal = $source('private.png');
  $privateFile = $create('private.png', 68, 'private://');
  $negative = $runner([$privateOriginal])->plan([(int) $privateFile->id()]);
  $assert(($negative['summary']['non_public_or_unsupported_uri'] ?? 0) === 1 && !$negative['records'], 'A private file must not become public.');
  $passed[] = 'private URI never exposed';

  $concurrent = $runner([$original])->plan([$fid]);
  $db->update('file_managed')->fields(['uri' => 'public://' . $nonce . '/concurrently-changed.png'])->condition('fid', $fid)->execute();
  $conflict = $repair->apply($concurrent, $private . '/conflict.jsonl');
  $assert($conflict['applied'] === 0 && $conflict['concurrent_conflicts'] === 1, 'A changed URI must not be overwritten.');
  $passed[] = 'concurrent URI update preserved';

  $db->update('file_managed')->fields(['uri' => $beforeFile['uri']])->condition('fid', $fid)->execute();
  $casePlan = $runner([$original])->plan([$fid]);
  $db->update('file_managed')->fields(['uri' => strtoupper($beforeFile['uri'])])->condition('fid', $fid)->execute();
  $caseConflict = $repair->apply($casePlan, $private . '/case-conflict.jsonl');
  $assert($caseConflict['applied'] === 0 && $caseConflict['concurrent_conflicts'] === 1, 'Case-only URI changes must not pass a case-insensitive SQL comparison.');
  $passed[] = 'case-only URI concurrency preserved';

  $db->update('file_managed')->fields(['uri' => $beforeFile['uri']])->condition('fid', $fid)->execute();
  $dimensionPlan = $runner([$original])->plan([$fid]);
  $repair->apply($dimensionPlan, $private . '/dimensions.jsonl');
  $db->update('media__field_media_image')->fields(['field_media_image_width' => 7])->condition('entity_id', $item->id())->condition('revision_id', $currentRevision)->execute();
  $blockedRollback = FALSE;
  try {
    $repair->rollback($private . '/dimensions.jsonl');
  }
  catch (RuntimeException $e) {
    $blockedRollback = str_contains($e->getMessage(), 'Concurrent dimensions');
  }
  $assert($blockedRollback, 'Rollback must reject dimensions edited after the repair.');
  $assert($db->select('file_managed', 'f')->fields('f', ['uri'])->condition('fid', $fid)->execute()->fetchField() === $original['uri'], 'A blocked rollback must leave the repaired URI unchanged.');
  $assert((int) $db->select('media__field_media_image', 't')->fields('t', ['field_media_image_width'])->condition('entity_id', $item->id())->execute()->fetchField() === 7, 'Rollback must preserve the subsequent editor change.');
  $passed[] = 'rollback refuses concurrent image edits';

  $ownedSource = $source('owned.png');
  $owner = File::create(['filename' => 'owned.png', 'uri' => $ownedSource['uri'], 'filemime' => 'image/png', 'filesize' => strlen($png), 'status' => 1]);
  $owner->save();
  $files[] = (int) $owner->id();
  $ownedFile = $create('owned.png');
  $negative = $runner([$ownedSource])->plan([(int) $ownedFile->id()]);
  $assert(($negative['summary']['uri_already_managed'] ?? 0) === 1 && !$negative['records'], 'An original already owned by another FID must not create duplicate URIs.');
  $passed[] = 'existing target ownership preserved';

  $sharedSource = $source('planned.png');
  $sharedA = $create('planned.png');
  $sharedB = $create('planned.png', 68, 'public://content/image/migrate/story/');
  $negative = $runner([$sharedSource])->plan([(int) $sharedA->id(), (int) $sharedB->id()]);
  $assert(($negative['summary']['planned_uri_collision'] ?? 0) === 2 && !$negative['records'], 'Two planned FIDs must not silently share one target URI.');
  $passed[] = 'planned URI collisions rejected';

  $assert($nodeCounters() === $beforeNodes, 'Node and node-revision counters/maximum IDs must stay unchanged.');
  $passed[] = 'node sequences and revisions unchanged';
}
finally {
  foreach (array_reverse($media) as $mid) {
    \Drupal::entityTypeManager()->getStorage('media')->resetCache([$mid]);
    if ($entity = Media::load($mid)) {
      $entity->delete();
    }
  }
  foreach (array_reverse($files) as $fid) {
    \Drupal::entityTypeManager()->getStorage('file')->resetCache([$fid]);
    if ($entity = File::load($fid)) {
      $entity->delete();
    }
  }
  $remove = static function (string $directory) use (&$remove): void {
    foreach (scandir($directory) ?: [] as $name) {
      if ($name === '.' || $name === '..') {
        continue;
      }
      $path = $directory . '/' . $name;
      is_dir($path) ? $remove($path) : unlink($path);
    }
    rmdir($directory);
  };
  $remove($fixtureRoot);
  $remove($private);
}
echo json_encode(['passed' => count($passed), 'cases' => $passed, 'node_writes' => 0, 'external_http_requests' => 0], JSON_PRETTY_PRINT) . PHP_EOL;
