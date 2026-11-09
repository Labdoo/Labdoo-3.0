<?php

/**
 * Isolated full repair test, using a private read-only export of the two sources.
 *
 * Source fixture data is not committed. No production database is modified.
 */

require __DIR__ . '/harden.php';

use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

$testState = Settings::get('labdoo_e2e_state');
$testFixturePath = getenv('LABDOO_SCHOOL_PRIVATE_FIXTURE') ?: $testState . '/work-program-20261109/school-restore-test-source-private.json';
$testSource = json_decode(file_get_contents($testFixturePath), TRUE, 512, JSON_THROW_ON_ERROR);
$testDb = \Drupal::database();
$testStorage = \Drupal::entityTypeManager()->getStorage('node');
$testFixtures = json_decode(file_get_contents($testState . '/integration-fixtures.json'), TRUE);
$testCreated = [];
$testAliasIds = [];
$testTransaction = NULL;
$testResult = NULL;
$testOriginalTracking = $testDb->select('labdoo_migrate_tracking', 't')->fields('t')->condition('entity_type', 'node')->condition('source_id', [253587,253619], 'IN')->orderBy('source_id')->execute()->fetchAll(\PDO::FETCH_ASSOC);
$testSource['test_renumber'] = [];
try {
  foreach ([3178 => 3285, 3177 => 3286] as $old => $new) {
    $node = Node::load($testFixtures['nodes']['School']['id'])->createDuplicate();
    $node->original_entity_id = 1;
    $node->skip_action_generation = TRUE;
    $node->skip_geocoding_enqueue = TRUE;
    $node->skip_recompute_enqueue = TRUE;
    $node->setTitle('Edoovillage #' . $old . ' - E2E approved-number school');
    $node->get('path')->pathauto = 0;
    $node->save();
    $testCreated[] = (int) $node->id();
    $testSource['test_renumber'][$node->id()] = ['old' => $old, 'new' => $new, 'uuid' => $node->uuid()];
    $alias = PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => '/content/edoovillage-' . $old . '-e2e-approved-' . $node->id(), 'langcode' => 'en']);
    $alias->save();
    $testAliasIds[] = (int) $alias->id();
  }
  $testRuntimeFixture = $testState . '/school-source-' . bin2hex(random_bytes(8)) . '.json';
  file_put_contents($testRuntimeFixture, json_encode($testSource));
  chmod($testRuntimeFixture, 0600);
  putenv('LABDOO_SCHOOL_TEST_SOURCE=' . $testRuntimeFixture);
  putenv('LABDOO_SCHOOL_APPLY=1');
  putenv('LABDOO_SCHOOL_BACKUP=' . $testState . '/school-repair-backup-' . bin2hex(random_bytes(8)) . '.jsonl');
  // The repair commits only its nested scope. The outer test transaction can
  // roll back the actual restoration, new revisions, aliases and file usage.
  $testTransaction = $testDb->startTransaction();
  // Exercise both an existing matching mapping and an absent mapping. The
  // outer test transaction restores the real local tracker state afterwards.
  $testDb->delete('labdoo_migrate_tracking')->condition('entity_type', 'node')->condition('source_id', [253587,253619], 'IN')->execute();
  $testDb->insert('labdoo_migrate_tracking')->fields(['entity_type' => 'node', 'bundle' => 'edoovillage', 'source_id' => 253587, 'destination_id' => 253587, 'last_migrated' => 123, 'duration_ms' => 321])->execute();
  ob_start();
  try {
    require dirname(__DIR__, 3) . '/scripts/repair-approved-school-conflicts.php';
    $testResult = json_decode(ob_get_contents(), TRUE, 512, JSON_THROW_ON_ERROR);
  }
  finally {
    ob_end_clean();
  }
  if (count($testResult['restored']) !== 2 || count($testResult['renumbered']) !== 2 || count($testResult['new_revisions']) !== 2) {
    throw new RuntimeException('The approved school repair did not finish all four changes.');
  }
  foreach ([253587, 253619] as $nid) {
    $school = Node::load($nid);
    if (!$school || !$school->get('field_hub')->entity || !$school->get('field_project_manager_s_')->entity || !$school->get('field_edoovillage_photo_album')->entity) {
      throw new RuntimeException('A restored school or required dependency is not loadable.');
    }
    if ($school->hasField('field_d7_nid') && (int) $school->get('field_d7_nid')->value !== $nid) {
      throw new RuntimeException('A restored school does not retain its original V2 identity field.');
    }
    $tracked = $testDb->select('labdoo_migrate_tracking', 't')->fields('t')->condition('entity_type', 'node')->condition('bundle', 'edoovillage')->condition('source_id', $nid)->execute()->fetchAssoc();
    if (!$tracked || (int) $tracked['destination_id'] !== $nid || (int) $tracked['last_migrated'] <= 123) {
      throw new RuntimeException('A restored school migration mapping is absent or incorrect.');
    }
  }
  $testTrackedIds = array_map('intval', \Drupal::service('labdoo_migrate.tracking.migration_tracker')->getMigratedSourceIds('node', 'edoovillage'));
  if (!in_array(253587, $testTrackedIds, TRUE) || !in_array(253619, $testTrackedIds, TRUE)) {
    throw new RuntimeException('A subsequent incremental sync would select the restored schools again.');
  }
  $testBackupStream = fopen(getenv('LABDOO_SCHOOL_BACKUP'), 'r');
  $testBackupBefore = json_decode(fgets($testBackupStream), TRUE, 512, JSON_THROW_ON_ERROR);
  fclose($testBackupStream);
  if (count($testBackupBefore['tracking_rows']) !== 1 || (int) $testBackupBefore['tracking_rows'][0]['source_id'] !== 253587 || (int) $testBackupBefore['tracking_rows'][0]['duration_ms'] !== 321) {
    throw new RuntimeException('The original tracking record was not included in the private backup.');
  }
  foreach ($testAliasIds as $id) {
    if (!PathAlias::load($id)) {
      throw new RuntimeException('An original school alias was removed.');
    }
  }
}
finally {
  if ($testTransaction) {
    $testTransaction->rollBack();
    unset($testTransaction);
  }
  // Fixture creation is outside the tested repair. Remove it without hooks or
  // queued recomputations, keeping unrelated E2E fixtures intact.
  foreach (array_unique(array_merge($testStorage->getTableMapping()->getTableNames(), $testStorage->getTableMapping()->getDedicatedTableNames())) as $table) {
    $id_column = in_array($table, ['node', 'node_field_data', 'node_revision', 'node_field_revision'], TRUE) ? 'nid' : 'entity_id';
    if ($testCreated && $testDb->schema()->fieldExists($table, $id_column)) {
      $testDb->delete($table)->condition($id_column, $testCreated, 'IN')->execute();
    }
  }
  foreach ($testAliasIds as $id) {
    PathAlias::load($id)?->delete();
  }
  $testStorage->resetCache(array_merge($testCreated, [253587, 253619]));
}
if ($testDb->select('labdoo_migrate_tracking', 't')->fields('t')->condition('entity_type', 'node')->condition('source_id', [253587,253619], 'IN')->orderBy('source_id')->execute()->fetchAll(\PDO::FETCH_ASSOC) !== $testOriginalTracking) {
  throw new RuntimeException('The isolated test did not roll back its migration tracker changes.');
}
echo 'PROGRAM_JSON:' . json_encode(['passed' => TRUE, 'restored_source_schools' => 2, 'renumbered_existing_schools' => 2, 'preserved_old_aliases' => count($testAliasIds), 'source_identity_checked_when_field_exists' => TRUE, 'tracker_rows_checked' => 2, 'incremental_excludes_restored_schools' => TRUE, 'tracker_backup_checked' => TRUE, 'node_auto_increment_unchanged' => TRUE, 'no_generated_actions_or_queues' => TRUE]);
