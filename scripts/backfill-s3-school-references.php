<?php

/**
 * Repair dangling S3 school references only when the current V2 school exists V3.
 *
 * Default read-only. LABDOO_S3_APPLY=1 requires LABDOO_S3_BACKUP pointing to a
 * new private JSONL file outside the document root. Does not restore absent
 * schools, save nodes, create revisions/actions, or allocate identifiers.
 */

use Drupal\Core\Cache\Cache;

$apply = getenv('LABDOO_S3_APPLY') === '1';
$backup = NULL;
if ($apply) {
  $path = getenv('LABDOO_S3_BACKUP');
  $parent = $path ? realpath(dirname($path)) : FALSE;
  if (!$parent || !str_starts_with($path, '/') || str_starts_with($parent . '/', realpath(\Drupal::root()) . '/')) {
    throw new RuntimeException('A new private backup outside the document root is required.');
  }
  $backup = fopen($path, 'x');
  if (!$backup || !chmod($path, 0600)) {
    throw new RuntimeException('Cannot create a private backup.');
  }
}
try {
  ob_start();
  try {
    require __DIR__ . '/audit-s3-school-references.php';
    $audit = json_decode(ob_get_contents(), TRUE, 512, JSON_THROW_ON_ERROR);
  }
  finally {
    ob_end_clean();
  }
  $db = \Drupal::database();
  $report = ['mode' => $apply ? 'apply' : 'dry-run', 'eligible' => [], 'repaired' => [], 'skipped_concurrent' => [], 'unresolved' => $audit['summary']];
  foreach ($audit['rows'] as $row) {
    if ($row['classification'] !== 'repairable_existing_school') {
      continue;
    }
    $nid = (int) $row['nid'];
    $school = (int) $row['existing_destination_candidates'][0];
    $report['eligible'][$nid] = $school;
    if (!$apply) {
      continue;
    }
    $transaction = $db->startTransaction();
    $meta = $db->query('SELECT n.nid,n.vid,d.langcode FROM {node} n INNER JOIN {node_field_data} d ON d.nid=n.nid AND d.default_langcode=1 WHERE n.nid=:nid FOR UPDATE', [':nid' => $nid])->fetchObject();
    $field = 'field_edoovillage_destination';
    $table = 'node__' . $field;
    $revision_table = 'node_revision__' . $field;
    $before = $db->select($table, 'f')->fields('f')->condition('entity_id', $nid)->condition('deleted', 0)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $changed = FALSE;
    foreach ($before as $current) {
      if ((int) $current[$field . '_target_id'] !== (int) $row['destination']) {
        $changed = TRUE;
      }
    }
    if ($changed || !$meta) {
      $report['skipped_concurrent'][] = $nid;
      unset($transaction);
      continue;
    }
    $revision_before = $db->select($revision_table, 'f')->fields('f')->condition('entity_id', $nid)->condition('revision_id', $meta->vid)->condition('deleted', 0)->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $after = $before ?: [['bundle' => 'dootronic', 'deleted' => 0, 'entity_id' => $nid, 'revision_id' => $meta->vid, 'langcode' => $meta->langcode, 'delta' => 0]];
    foreach ($after as &$value) {
      $value[$field . '_target_id'] = $school;
      $value['revision_id'] = $meta->vid;
    }
    unset($value);
    $entry = ['field' => $field, 'nid' => $nid, 'vid' => $meta->vid, 'before' => $before, 'revision_before' => $revision_before, 'after' => $after];
    if (fwrite($backup, json_encode($entry, JSON_THROW_ON_ERROR) . PHP_EOL) === FALSE || !fflush($backup) || !fsync($backup)) {
      throw new RuntimeException('Cannot persist backup; transaction aborted.');
    }
    $db->delete($table)->condition('entity_id', $nid)->condition('deleted', 0)->execute();
    $db->delete($revision_table)->condition('entity_id', $nid)->condition('revision_id', $meta->vid)->condition('deleted', 0)->execute();
    foreach ($after as $value) {
      $db->insert($table)->fields($value)->execute();
      $db->insert($revision_table)->fields($value)->execute();
    }
    unset($transaction);
    $report['repaired'][$nid] = $school;
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$nid]);
    Cache::invalidateTags(['node:' . $nid, 'node_list:dootronic']);
  }
  if ($apply && count($report['repaired']) > 0) {
    Cache::invalidateTags(['node_list']);
  }
  echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
catch (\Throwable $exception) {
  if (isset($transaction)) {
    $transaction->rollBack();
  }
  throw $exception;
}
finally {
  if (is_resource($backup)) {
    fclose($backup);
  }
}
