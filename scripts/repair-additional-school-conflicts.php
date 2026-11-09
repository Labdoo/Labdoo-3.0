<?php

/**
 * Additional approved repair: restore Dambo/Kabwata and renumber one V3 school.
 *
 * Default read-only. Apply requires LABDOO_ADDITIONAL_SCHOOL_APPLY=1 and a new private
 * LABDOO_ADDITIONAL_SCHOOL_BACKUP JSONL path outside the document root. Source must still
 * end at #3284; only the exact approved #3287 assignment is permitted.
 */

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

function labdooAdditionalSchoolMaxNumber($db, bool $source): int {
  $sql = $source ? "SELECT title FROM {node} WHERE type='edoovillage'" : "SELECT title FROM {node_field_data} WHERE type='edoovillage' AND default_langcode=1";
  $max = 0;
  foreach ($db->query($sql) as $row) {
    if (preg_match('/^Edoovillage\s+#(\d+)/i', $row->title, $match)) {
      $max = max($max, (int) $match[1]);
    }
  }
  return $max;
}

function labdooAdditionalSchoolNodeCounters($db): array {
  return [
    'nodes' => $db->query('SELECT COUNT(*) AS count,MAX(nid) AS max FROM {node}')->fetchAssoc(),
    'revisions' => $db->query('SELECT COUNT(*) AS count,MAX(vid) AS max FROM {node_revision}')->fetchAssoc(),
    'auto_increment' => $db->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:name", [':name' => $db->getPrefix() . 'node'])->fetchField(),
    'actions' => $db->query("SELECT COUNT(*) FROM {node} WHERE type='action'")->fetchField(),
    'queues' => $db->query('SELECT name,COUNT(*) AS count,MAX(item_id) AS max FROM {queue} GROUP BY name ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC),
  ];
}

$apply = getenv('LABDOO_ADDITIONAL_SCHOOL_APPLY') === '1';
$db = \Drupal::database();
$connection_key = $db->getKey();
$storage = \Drupal::entityTypeManager()->getStorage('node');
$renumber = [
  723662 => ['old' => 3176, 'new' => 3287, 'uuid' => 'c2a7ca7f-cdc6-4727-a9a4-cca6764aee78'],
];
$restore = [253559 => 3175, 253564 => 3176];
$lock = \Drupal::lock();
if (!$lock->acquire('labdoo.additional_school_repair', 3600)) {
  throw new RuntimeException('Another approved school repair is running.');
}
$backup = NULL;
$transaction = NULL;
$state = \Drupal::state();
$state_keys = ['labdoo_migrate_is_running', 'labdoo_geocoding_disabled'];
$state_before = [];
foreach ($state_keys as $name) {
  $sentinel = new stdClass();
  $value = $state->get($name, $sentinel);
  $state_before[$name] = ['exists' => $value !== $sentinel, 'value' => $value === $sentinel ? NULL : $value];
}
try {
  $config = \Drupal::service('labdoo_migrate.config.configuration_manager')->getContentConfiguration('edoovillage')->getFieldsMapping();
  // Location instances must belong to the current V2 revision.
  foreach ($config as &$field_config) {
    foreach ($field_config['source']['fields'] as &$source_field) {
      foreach ($source_field['joins'] ?? [] as $i => $join) {
        if ($join['table'] === 'node' && str_contains($join['condition'], 'location_instance')) {
          $source_field['joins'][$i]['condition'] .= ' AND node.vid = location_instance.vid';
        }
      }
    }
  }
  unset($field_config, $source_field);
  $mapping = \Drupal::service('labdoo_migrate.mapper.fields_mapper')->buildMapping($config);
  $fixture_path = getenv('LABDOO_ADDITIONAL_SCHOOL_TEST_SOURCE');
  if ($fixture_path) {
    if (!Settings::get('labdoo_e2e', FALSE) || !str_contains(\Drupal::root(), '/.e2e-lab/') || !str_starts_with($db->getConnectionOptions()['database'], 'labdoo_e2e_')) {
      throw new RuntimeException('Offline source fixtures are allowed only in an isolated E2E site.');
    }
    $source_data = json_decode(file_get_contents($fixture_path), TRUE, 512, JSON_THROW_ON_ERROR);
    if (isset($source_data['test_renumber'])) {
      $renumber = $source_data['test_renumber'];
    }
  }
  else {
    try {
      $source = \Drupal::service('labdoo_migrate.database.external_connection_manager')->setConnection();
      $source_data = ['max_number' => labdooAdditionalSchoolMaxNumber($source, TRUE)];
      $source_data['nodes'] = $source->query('SELECT nid,type,title,status,uid,created,changed FROM {node} WHERE nid IN (253559,253564)')->fetchAllAssoc('nid', \PDO::FETCH_ASSOC);
      $source_data['aliases'] = $source->query("SELECT source,alias,language FROM {url_alias} WHERE source IN('node/253559','node/253564')")->fetchAll(\PDO::FETCH_ASSOC);
      $source_data['payload'] = \Drupal::service('labdoo_migrate.source_content.repository.node')->getEntities('edoovillage', $mapping, array_keys($restore));
    }
    finally {
      Database::setActiveConnection($connection_key);
    }
  }
  $tracking_before = $db->query('SELECT * FROM {labdoo_migrate_tracking} WHERE entity_type=:type AND (source_id IN (:sources[]) OR destination_id IN (:destinations[])) ORDER BY source_id', [':type' => 'node', ':sources[]' => array_keys($restore), ':destinations[]' => array_keys($restore)])->fetchAll(\PDO::FETCH_ASSOC);
  foreach ($tracking_before as $row) {
    $source_id = (int) $row['source_id'];
    if ($row['bundle'] !== 'edoovillage' || !isset($restore[$source_id]) || (int) $row['destination_id'] !== $source_id) {
      throw new RuntimeException('An approved school has a conflicting migration tracking mapping.');
    }
  }
  $source_identity_field = Node::create(['type' => 'edoovillage'])->hasField('field_d7_nid');
  if ($source_identity_field) {
    $identity_nodes = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('field_d7_nid', array_keys($restore), 'IN')->execute();
    foreach ($identity_nodes as $identity_nid) {
      if (!isset($restore[(int) $identity_nid])) {
        throw new RuntimeException('An approved V2 school identity already belongs to another V3 node.');
      }
    }
  }
  $already_done = TRUE;
  foreach ($renumber as $nid => $change) {
    $node = $storage->load($nid);
    if (!$node || $node->uuid() !== $change['uuid']) {
      throw new RuntimeException('An approved existing school or its UUID differs.');
    }
    $already_done = $already_done && str_starts_with($node->getTitle(), 'Edoovillage #' . $change['new'] . ' - ');
  }
  foreach ($restore as $nid => $number) {
    $node = $storage->load($nid);
    $already_done = $already_done && $node && str_starts_with($node->getTitle(), 'Edoovillage #' . $number . ' - ');
  }
  if ($already_done) {
    $tracked_ids = array_map(static fn($row) => (int) $row['source_id'], $tracking_before);
    foreach (array_keys($restore) as $nid) {
      $existing = $storage->load($nid);
      if (!in_array($nid, $tracked_ids, TRUE) || ($existing->hasField('field_d7_nid') && (int) $existing->get('field_d7_nid')->value !== $nid)) {
        throw new RuntimeException('Restored schools have incomplete migration identity metadata; review is required.');
      }
    }
    echo json_encode(['mode' => 'already-applied', 'restored' => $restore, 'renumbered' => $renumber], JSON_PRETTY_PRINT) . PHP_EOL;
    return;
  }
  if ((int) $source_data['max_number'] !== 3284 || labdooAdditionalSchoolMaxNumber($db, FALSE) >= 3287) {
    throw new RuntimeException('Numbering advanced since approval; the exact approved new numbers are no longer safe.');
  }
  foreach ($renumber as $nid => $change) {
    if (!str_starts_with($storage->load($nid)->getTitle(), 'Edoovillage #' . $change['old'] . ' - ')) {
      throw new RuntimeException('An existing school no longer has its approved original number.');
    }
  }
  foreach ($restore as $nid => $number) {
    $source_node = $source_data['nodes'][$nid] ?? NULL;
    if ($storage->load($nid) || !$source_node || $source_node['type'] !== 'edoovillage' || !str_starts_with($source_node['title'], 'Edoovillage #' . $number . ' - ')) {
      throw new RuntimeException('A school to restore is no longer absent or its source identity differs.');
    }
  }
  $public_number_owners = [];
  foreach ($db->query("SELECT n.nid,n.title FROM {node_field_data} n INNER JOIN {node} b ON b.nid=n.nid WHERE n.type='edoovillage' AND n.default_langcode=1") as $owner) {
    if (preg_match('/^Edoovillage\s+#(\d+)\b/i', $owner->title, $match)) {
      $public_number_owners[(int) $match[1]][] = (int) $owner->nid;
    }
  }
  foreach ($restore as $nid => $number) {
    foreach ($public_number_owners[$number] ?? [] as $owner_nid) {
      if (!isset($renumber[$owner_nid]) || $renumber[$owner_nid]['old'] !== $number) {
        throw new RuntimeException('An additional source school number was allocated to another real V3 node; review is required.');
      }
    }
  }
  $dependency_report = [];
  foreach ($source_data['payload'] as $nid => $languages) {
    foreach ($languages as $lang => $values) {
      if ($lang === 'metadata') {
        continue;
      }
      foreach ($values as $identifier => $value) {
        $definition = $mapping[$identifier] ?? NULL;
        if (!$definition) {
          continue;
        }
        $field = $definition->getDestinationField()->getFieldName();
        if (in_array($field, ['uid', 'field_hub', 'field_project_manager_s_', 'field_edoo_additional_followers', 'field_edoo_additional_editors', 'field_edoovillage_photo_album'], TRUE)) {
          $type = in_array($field, ['field_hub', 'field_edoovillage_photo_album'], TRUE) ? 'node' : 'user';
          foreach (is_array($value) ? $value : [$value] as $id) {
            if ((int) $id > 0 && !\Drupal::entityTypeManager()->getStorage($type)->load((int) $id)) {
              throw new RuntimeException('A required existing school dependency is absent: ' . $type . ':' . $id);
            }
          }
          $dependency_report[$nid][$field] = $value;
        }
        if ($definition->getDestinationField()->getSpecialType()?->getType() === 'managed_file') {
          foreach (is_array($value) ? $value : [$value] as $uri) {
            if (!str_starts_with($uri, 'public://') || !is_file($uri)) {
              throw new RuntimeException('A required synchronized school attachment is unavailable.');
            }
          }
        }
      }
    }
  }
  $aliases_before = $db->select('path_alias', 'a')->fields('a')->condition('path', array_map(static fn($nid) => '/node/' . $nid, array_merge(array_keys($restore), array_keys($renumber))), 'IN')->execute()->fetchAll(\PDO::FETCH_ASSOC);
  $aliases_to_add = [];
  foreach ($aliases_before as $alias) {
    $nid = (int) basename($alias['path']);
    if (isset($renumber[$nid])) {
      $new_alias = preg_replace('/edoovillage-' . $renumber[$nid]['old'] . '-/', 'edoovillage-' . $renumber[$nid]['new'] . '-', $alias['alias'], 1);
      if ($new_alias !== $alias['alias']) {
        $aliases_to_add[] = ['path' => $alias['path'], 'alias' => $new_alias, 'langcode' => $alias['langcode']];
      }
    }
  }
  foreach ($source_data['aliases'] as $alias) {
    $nid = (int) basename($alias['source']);
    $aliases_to_add[] = ['path' => '/node/' . $nid, 'alias' => '/' . ltrim($alias['alias'], '/'), 'langcode' => $alias['language'] === 'und' ? 'en' : $alias['language']];
  }
  foreach ($aliases_to_add as $alias) {
    $conflicts = $db->select('path_alias', 'a')->fields('a', ['path'])->condition('alias', $alias['alias'])->condition('langcode', [$alias['langcode'], 'und'], 'IN')->execute()->fetchCol();
    foreach ($conflicts as $path) {
      if ($path !== $alias['path']) {
        throw new RuntimeException('A proposed alias already belongs to another entity.');
      }
    }
  }
  $counters_before = labdooAdditionalSchoolNodeCounters($db);
  $plan = ['mode' => $apply ? 'apply' : 'dry-run', 'restore' => $restore, 'renumber' => $renumber, 'scope' => 'additional-approved-dambo-kabwata-kathmandu', 'source_max' => $source_data['max_number'], 'dependencies' => $dependency_report, 'aliases_to_add' => $aliases_to_add, 'old_aliases_preserved' => count($aliases_before), 'counters_before' => $counters_before];
  if (!$apply) {
    echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    return;
  }
  $backup_path = getenv('LABDOO_ADDITIONAL_SCHOOL_BACKUP');
  $parent = $backup_path ? realpath(dirname($backup_path)) : FALSE;
  if (!$parent || !str_starts_with($backup_path, '/') || str_starts_with($parent . '/', realpath(\Drupal::root()) . '/')) {
    throw new RuntimeException('Apply requires a new private backup outside the document root.');
  }
  $backup = fopen($backup_path, 'x');
  if (!$backup || !chmod($backup_path, 0600)) {
    throw new RuntimeException('Cannot create the private school backup.');
  }
  $snapshot = [];
  foreach (array_unique(array_merge($storage->getTableMapping()->getTableNames(), $storage->getTableMapping()->getDedicatedTableNames())) as $table) {
    $id_column = in_array($table, ['node', 'node_field_data', 'node_revision', 'node_field_revision'], TRUE) ? 'nid' : 'entity_id';
    if ($db->schema()->fieldExists($table, $id_column)) {
      $snapshot[$table] = $db->select($table, 't')->fields('t')->condition($id_column, array_merge(array_keys($restore), array_keys($renumber)), 'IN')->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
  }
  $entry = ['kind' => 'before', 'plan' => $plan, 'node_tables' => $snapshot, 'aliases' => $aliases_before, 'source' => $source_data, 'state' => $state_before, 'tracking_rows' => $tracking_before];
  if (fwrite($backup, json_encode($entry, JSON_THROW_ON_ERROR) . PHP_EOL) === FALSE || !fflush($backup) || !fsync($backup)) {
    throw new RuntimeException('Cannot persist the backup before repair.');
  }
  $transaction = $db->startTransaction();
  $tracking_now = $db->query('SELECT * FROM {labdoo_migrate_tracking} WHERE entity_type=:type AND bundle=:bundle AND source_id IN (:ids[]) ORDER BY source_id FOR UPDATE', [':type' => 'node', ':bundle' => 'edoovillage', ':ids[]' => array_keys($restore)])->fetchAll(\PDO::FETCH_ASSOC);
  if ($tracking_now !== $tracking_before) {
    throw new RuntimeException('School tracking changed since backup; repair aborted.');
  }
  $GLOBALS['labdoo_scoped_school_repair'] = array_fill_keys(array_keys($restore), TRUE) + array_fill_keys(array_keys($renumber), FALSE);
  foreach ($state_keys as $name) {
    $state->set($name, TRUE);
  }
  $new_revisions = [];
  foreach ($renumber as $nid => $change) {
    $node = $storage->load($nid);
    $original_vid = $node->getRevisionId();
    $original_title = $node->getTitle();
    foreach ($node->getTranslationLanguages() as $lang => $language) {
      $translation = $node->getTranslation($lang);
      $translation->setTitle(preg_replace('/^Edoovillage\s+#' . $change['old'] . '\b/', 'Edoovillage #' . $change['new'], $translation->getTitle(), 1));
    }
    $node->original_entity_id = $nid;
    $node->skip_action_generation = TRUE;
    $node->skip_recompute_enqueue = TRUE;
    $node->skip_geocoding_enqueue = TRUE;
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage('Additional approved repair: preserve V2 #3175/#3176 and retain original V3 school identity and aliases.');
    $node->setRevisionCreationTime(time());
    $node->setChangedTime(time());
    $node->get('path')->pathauto = 0;
    $node->save();
    $old_revision = $storage->loadRevision($original_vid);
    if (!$old_revision || $old_revision->getTitle() !== $original_title || $node->uuid() !== $change['uuid']) {
      throw new RuntimeException('Original school revision or UUID was not preserved.');
    }
    $new_revisions[$nid] = $node->getRevisionId();
  }
  foreach ($restore as $nid => $number) {
    $entity_values = $source_data['payload'][$nid];
    $lang = $entity_values['metadata']['main_langcode'];
    $node = Node::create(['type' => 'edoovillage', 'nid' => $nid, 'langcode' => $lang, 'status' => (int) $source_data['nodes'][$nid]['status']]);
    $node->enforceIsNew(TRUE);
    foreach ($entity_values as $language => $values) {
      if ($language === 'metadata') {
        continue;
      }
      $translation = $language === $lang ? $node : $node->addTranslation($language);
      $accumulated = [];
      foreach ($values as $identifier => $value) {
        $mapped = $mapping[$identifier] ?? NULL;
        if (!$mapped) {
          continue;
        }
        $destination = $mapped->getDestinationField();
        $field = $destination->getFieldName();
        if (!$translation->hasField($field) || $field === 'nid') {
          continue;
        }
        $special = $destination->getSpecialType();
        if ($special?->getType() === 'managed_file') {
          $files = [];
          foreach (is_array($value) ? $value : [$value] as $uri) {
            $existing = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
            $file = $existing ? reset($existing) : File::create(['uri' => $uri, 'filename' => basename($uri), 'status' => 1, 'uid' => (int) $source_data['nodes'][$nid]['uid']]);
            if ($file->isNew()) {
              $file->save();
            }
            $files[] = ['target_id' => $file->id()];
          }
          $value = $files;
        }
        elseif ($special?->getType() === 'geolocation') {
          $points = [];
          foreach (is_array($value) ? $value : [$value] as $point) {
            [$lat, $lon] = array_map('floatval', explode(',', $point));
            if (abs($lat) > 90 || abs($lon) > 180 || ($lat === 0.0 && $lon === 0.0) || ($lat === -90.0 && $lon === -180.0)) {
              throw new RuntimeException('A source school location is invalid.');
            }
            $points[] = ['value' => sprintf('POINT (%.12F %.12F)', $lon, $lat)];
          }
          $value = $points;
        }
        elseif ($special) {
          throw new RuntimeException('An unexpected special field type needs review before school restoration.');
        }
        $field_definition = $translation->getFieldDefinition($field);
        if ($field === 'field_country') {
          $value = strtoupper((string) $value);
        }
        if ($field === 'field_address') {
          $value = ['country_code' => strtoupper((string) ($values['location.country'] ?? '')), 'locality' => $values['location.city'] ?? '', 'address_line1' => (string) $value, 'postal_code' => $values['location.postal_code'] ?? ''];
        }
        if (in_array($field_definition->getType(), ['text', 'text_long', 'text_with_summary'], TRUE) && is_string($value)) {
          $value = ['value' => $value, 'format' => 'full_html'];
        }
        $list = is_array($value) && array_is_list($value);
        if ($field_definition->getFieldStorageDefinition()->isMultiple()) {
          $accumulated[$field] = array_merge($accumulated[$field] ?? [], $list ? $value : [$value]);
        }
        else {
          $accumulated[$field] = $list ? reset($value) : $value;
        }
      }
      foreach ($accumulated as $field => $value) {
        $translation->set($field, $value);
      }
      if ($translation->hasField('field_address') && $translation->get('field_address')->isEmpty()) {
        $translation->set('field_address', ['country_code' => strtoupper((string) $translation->get('field_country')->value), 'locality' => $translation->get('field_city')->value]);
      }
    }
    if ($node->hasField('field_d7_nid')) {
      if ((int) $node->get('field_d7_nid')->value !== 0 && (int) $node->get('field_d7_nid')->value !== $nid) {
        throw new RuntimeException('Restored school has a conflicting original V2 node identity.');
      }
      $node->set('field_d7_nid', $nid);
    }
    $node->original_entity_id = $nid;
    $node->skip_action_generation = TRUE;
    $node->skip_recompute_enqueue = TRUE;
    $node->skip_geocoding_enqueue = TRUE;
    $node->labdoo_skip_geocoding = TRUE;
    $node->get('path')->pathauto = 0;
    $node->setRevisionLogMessage('Restore existing V2 school with its original node ID; no generated activity or gallery.');
    $node->save();
    if ((int) $node->id() !== $nid || !str_starts_with($node->getTitle(), 'Edoovillage #' . $number . ' - ')) {
      throw new RuntimeException('Restored school did not retain its approved identity.');
    }
    \Drupal::service('labdoo_migrate.tracking.migration_tracker')->track('node', 'edoovillage', $nid, $nid);
    $tracked_destination = $db->select('labdoo_migrate_tracking', 't')->fields('t', ['destination_id'])->condition('entity_type', 'node')->condition('bundle', 'edoovillage')->condition('source_id', $nid)->execute()->fetchField();
    if ((int) $tracked_destination !== $nid) {
      throw new RuntimeException('Restored school migration identity was not recorded.');
    }
  }
  foreach ($aliases_to_add as $alias) {
    if (!\Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties($alias)) {
      PathAlias::create($alias + ['status' => 1])->save();
    }
  }
  $counters_after = labdooAdditionalSchoolNodeCounters($db);
  if ((int) $counters_after['revisions']['count'] !== (int) $counters_before['revisions']['count'] + 3 || (int) $counters_after['nodes']['count'] !== (int) $counters_before['nodes']['count'] + 2 || $counters_after['nodes']['max'] !== $counters_before['nodes']['max'] || $counters_after['auto_increment'] !== $counters_before['auto_increment'] || $counters_after['actions'] !== $counters_before['actions'] || $counters_after['queues'] !== $counters_before['queues']) {
    throw new RuntimeException('Unexpected node, sequence, action or queue changes; repair rolled back.');
  }
  if (!$fixture_path) {
    try {
      $source = \Drupal::service('labdoo_migrate.database.external_connection_manager')->setConnection();
      if (labdooAdditionalSchoolMaxNumber($source, TRUE) !== 3284) {
        throw new RuntimeException('V2 school numbering advanced during repair; transaction rolled back.');
      }
    }
    finally {
      Database::setActiveConnection($connection_key);
    }
  }
  $result = ['kind' => 'after', 'restored' => $restore, 'renumbered' => $renumber, 'new_revisions' => $new_revisions, 'aliases_added' => $aliases_to_add, 'counters_after' => $counters_after];
  if (fwrite($backup, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL) === FALSE || !fflush($backup) || !fsync($backup)) {
    throw new RuntimeException('Cannot persist the result; repair rolled back.');
  }
  unset($transaction);
  $storage->resetCache(array_merge(array_keys($restore), array_keys($renumber)));
  $dependent_ids = [];
  foreach (\Drupal::service('entity_field.manager')->getFieldStorageDefinitions('node') as $field_name => $definition) {
    if ($definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'node') {
      $table = 'node__' . $field_name;
      if ($db->schema()->tableExists($table)) {
        $references = $db->select($table, 'f')->fields('f', ['entity_id'])->condition($field_name . '_target_id', array_keys($restore), 'IN')->condition('deleted', 0)->execute()->fetchCol();
        $dependent_ids = array_merge($dependent_ids, $references);
      }
    }
  }
  $dependent_ids = array_unique($dependent_ids);
  $storage->resetCache($dependent_ids);
  Cache::invalidateTags(array_merge(array_map(static fn($id) => 'node:' . $id, $dependent_ids), ['node_list', 'node_list:edoovillage', 'path_alias_list']));
  echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
catch (\Throwable $exception) {
  if (isset($transaction)) {
    $transaction->rollBack();
  }
  throw $exception;
}
finally {
  Database::setActiveConnection($connection_key);
  unset($GLOBALS['labdoo_scoped_school_repair']);
  if ($apply) {
    foreach ($state_before as $name => $record) {
      if ($record['exists']) {
        $state->set($name, $record['value']);
      }
      else {
        $state->delete($name);
      }
    }
  }
  if (is_resource($backup)) {
    fclose($backup);
  }
  $lock->release('labdoo.additional_school_repair');
}
