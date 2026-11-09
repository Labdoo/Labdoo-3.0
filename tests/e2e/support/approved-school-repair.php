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
echo 'PROGRAM_JSON:' . json_encode(['passed' => TRUE, 'restored_source_schools' => 2, 'renumbered_existing_schools' => 2, 'preserved_old_aliases' => count($testAliasIds), 'node_auto_increment_unchanged' => TRUE, 'no_generated_actions_or_queues' => TRUE]);
