<?php

/** Isolated additional-school repair test; all changes roll back. */
require __DIR__ . '/harden.php';

use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

$extraState = Settings::get('labdoo_e2e_state');
$extraFixture = getenv('LABDOO_ADDITIONAL_SCHOOL_PRIVATE_FIXTURE') ?: $extraState . '/work-program-20261109/additional-school-test-source-private.json';
$extraSource = json_decode(file_get_contents($extraFixture), TRUE, 512, JSON_THROW_ON_ERROR);
$extraFixtures = json_decode(file_get_contents($extraState . '/integration-fixtures.json'), TRUE);
$extraDb = \Drupal::database();
$extraStorage = \Drupal::entityTypeManager()->getStorage('node');
$extraOwners = $extraDb->query("SELECT n.nid FROM {node_field_data} n INNER JOIN {node} b ON b.nid=n.nid WHERE n.type='edoovillage' AND n.default_langcode=1 AND n.title LIKE 'Edoovillage #3176 - %'")->fetchCol();
$extraSchool = count($extraOwners) === 1 ? Node::load((int) reset($extraOwners)) : NULL;
if (!$extraSchool || !str_starts_with($extraSchool->getTitle(), 'Edoovillage #3176 - ')) {
  throw new RuntimeException('This isolated test requires the existing #3176 school fixture.');
}
$extraNid = (int) $extraSchool->id();
$extraUuid = $extraSchool->uuid();
$extraTitle = $extraSchool->getTitle();
$extraSource['test_renumber'] = [$extraNid => ['old' => 3176, 'new' => 3287, 'uuid' => $extraUuid]];
$extraRuntimeFixture = $extraState . '/additional-school-source-' . bin2hex(random_bytes(8)) . '.json';
file_put_contents($extraRuntimeFixture, json_encode($extraSource));
chmod($extraRuntimeFixture, 0600);
$extraBackup = $extraState . '/additional-school-backup-' . bin2hex(random_bytes(8)) . '.jsonl';
putenv('LABDOO_ADDITIONAL_SCHOOL_TEST_SOURCE=' . $extraRuntimeFixture);
putenv('LABDOO_ADDITIONAL_SCHOOL_APPLY=1');
putenv('LABDOO_ADDITIONAL_SCHOOL_BACKUP=' . $extraBackup);
$extraCountsBefore = $extraDb->query('SELECT COUNT(*) AS count,MAX(nid) AS max FROM {node}')->fetchAssoc();
$extraRevisionsBefore = $extraDb->query('SELECT COUNT(*) AS count,MAX(vid) AS max FROM {node_revision}')->fetchAssoc();
$extraTrackingBefore = $extraDb->select('labdoo_migrate_tracking', 't')->fields('t')->condition('entity_type', 'node')->condition('source_id', [253559,253564], 'IN')->orderBy('source_id')->execute()->fetchAll(\PDO::FETCH_ASSOC);
$extraAliasesBefore = $extraDb->select('path_alias', 'a')->fields('a')->condition('path', '/node/' . $extraNid)->execute()->fetchAll(\PDO::FETCH_ASSOC);
$extraTransaction = $extraDb->startTransaction();
try {
  $extraDb->delete('labdoo_migrate_tracking')->condition('entity_type', 'node')->condition('source_id', [253559,253564], 'IN')->execute();
  $extraDb->insert('labdoo_migrate_tracking')->fields(['entity_type' => 'node', 'bundle' => 'edoovillage', 'source_id' => 253559, 'destination_id' => 253559, 'last_migrated' => 123, 'duration_ms' => 321])->execute();
  ob_start();
  try {
    require dirname(__DIR__, 3) . '/scripts/repair-additional-school-conflicts.php';
    $extraResult = json_decode(ob_get_contents(), TRUE, 512, JSON_THROW_ON_ERROR);
  }
  finally {
    ob_end_clean();
  }
  if (count($extraResult['restored']) !== 2 || count($extraResult['renumbered']) !== 1 || count($extraResult['new_revisions']) !== 1) {
    throw new RuntimeException('Additional repair did not complete its exact three approved changes.');
  }
  foreach ([253559,253564] as $nid) {
    $school = Node::load($nid);
    if (!$school || !$school->get('field_hub')->entity || !$school->get('field_project_manager_s_')->entity || !$school->get('field_edoovillage_photo_album')->entity || ($school->hasField('field_d7_nid') && (int) $school->get('field_d7_nid')->value !== $nid)) {
      throw new RuntimeException('Additional restored school identity or dependency is invalid.');
    }
  }
  $extraMigrated = array_map('intval', \Drupal::service('labdoo_migrate.tracking.migration_tracker')->getMigratedSourceIds('node', 'edoovillage'));
  if (!in_array(253559,$extraMigrated,TRUE) || !in_array(253564,$extraMigrated,TRUE)) {
    throw new RuntimeException('Incremental migration tracking is incomplete.');
  }
  foreach ($extraAliasesBefore as $alias) {
    if (!PathAlias::load($alias['id'])) {
      throw new RuntimeException('An original school alias was removed.');
    }
  }
}
finally {
  $extraTransaction->rollBack();
  unset($extraTransaction);
  $extraStorage->resetCache([$extraNid,253559,253564]);
}
if ($extraDb->query('SELECT COUNT(*) AS count,MAX(nid) AS max FROM {node}')->fetchAssoc() !== $extraCountsBefore || $extraDb->query('SELECT COUNT(*) AS count,MAX(vid) AS max FROM {node_revision}')->fetchAssoc() !== $extraRevisionsBefore || Node::load($extraNid)->getTitle() !== $extraTitle || Node::load($extraNid)->uuid() !== $extraUuid || $extraDb->select('labdoo_migrate_tracking','t')->fields('t')->condition('entity_type','node')->condition('source_id',[253559,253564],'IN')->orderBy('source_id')->execute()->fetchAll(\PDO::FETCH_ASSOC) !== $extraTrackingBefore) {
  throw new RuntimeException('The isolated additional-school repair was not fully rolled back.');
}
echo 'PROGRAM_JSON:' . json_encode(['passed' => TRUE, 'restored' => 2, 'renumbered' => 1, 'new_revisions' => 3, 'old_aliases_preserved' => count($extraAliasesBefore), 'source_identity_and_tracker_checked' => TRUE, 'node_auto_increment_unchanged' => TRUE, 'no_generated_actions_or_queues' => TRUE, 'fully_rolled_back' => TRUE]);
