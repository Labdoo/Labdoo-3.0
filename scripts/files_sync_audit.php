<?php

/**
 * @file
 * Read-only public-file presence check and small source checksum sample.
 *
 * Does not repair file entities, contact remote URLs or create any content.
 */

$database = \Drupal::database();
$root = \Drupal::service('file_system')->realpath('public://');
$sourceRoot = '/mnt/drupal-files-source';
$report = [
  'captured_at' => gmdate('c'),
  'managed_public_files' => 0,
  'managed_public_present' => 0,
  'managed_public_missing' => 0,
  'managed_private_files' => 0,
  'other_or_invalid_uri' => 0,
  'small_checksum_samples' => [],
  'missing_sample_fids' => [],
];
$last = 0;
do {
  $rows = $database->queryRange('SELECT fid, uri FROM {file_managed} WHERE fid > :last ORDER BY fid', 0, 500, [':last' => $last])->fetchAll();
  foreach ($rows as $row) {
    $last = (int) $row->fid;
    if (str_starts_with($row->uri, 'private://')) {
      $report['managed_private_files']++;
      continue;
    }
    if (!str_starts_with($row->uri, 'public://')) {
      $report['other_or_invalid_uri']++;
      continue;
    }
    $report['managed_public_files']++;
    $relative = substr($row->uri, strlen('public://'));
    if (str_contains($relative, '..')) {
      $report['managed_public_missing']++;
      continue;
    }
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
      $report['managed_public_missing']++;
      if (count($report['missing_sample_fids']) < 10) {
        $report['missing_sample_fids'][] = $last;
      }
      continue;
    }
    $report['managed_public_present']++;
    if (count($report['small_checksum_samples']) < 8 && filesize($path) <= 2097152 && preg_match('/\.(jpe?g|png|gif|pdf)$/i', $relative) && is_file($sourceRoot . '/' . $relative)) {
      $report['small_checksum_samples'][] = [
        'fid' => $last,
        'uri' => $row->uri,
        'bytes' => filesize($path),
        'sha256_matches_source' => hash_file('sha256', $path) === hash_file('sha256', $sourceRoot . '/' . $relative),
      ];
    }
  }
} while (count($rows) === 500);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
