<?php

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\node\Entity\Node;

require __DIR__ . '/harden.php';
umask(0077);
$state = Settings::get('labdoo_e2e_state');
$variant = Settings::get('labdoo_e2e_variant');
if ($variant !== 'integration' || !is_file($state . '/v2-import-complete.json')) {
  throw new RuntimeException('A completed, isolated V2 import is required');
}
$options = json_decode(file_get_contents($state . '/v2-readonly-connection.json'), TRUE, 512, JSON_THROW_ON_ERROR);
if ($options['database'] !== 'labdoo_e2e_v2_20261107' || $options['username'] !== 'labdoo_e2e_v2_read') {
  throw new RuntimeException('Unexpected V2 fixture connection');
}
// Override the source only for this guarded CLI process, not the application.
Database::removeConnection('external');
Database::addConnectionInfo('external', 'default', $options);
$external = Database::getConnection('default', 'external');
if ($external->getConnectionOptions()['database'] !== 'labdoo_e2e_v2_20261107'
  || !(int) $external->query('SELECT @@session.tx_read_only')->fetchField()) {
  throw new RuntimeException('V2 must use the isolated, read-only connection');
}
$grants = $external->query('SHOW GRANTS FOR CURRENT_USER')->fetchCol();
$expectedGrant = FALSE;
foreach ($grants as $grant) {
  $scope = str_replace(['`', '"'], '', explode(' TO ', $grant, 2)[0]);
  if ($scope === 'GRANT SELECT ON labdoo_e2e_v2_20261107.*') { $expectedGrant = TRUE; }
  elseif ($scope !== 'GRANT USAGE ON *.*') {
    throw new RuntimeException('Unexpected V2 reader privileges');
  }
}
if (!$expectedGrant) { throw new RuntimeException('Missing SELECT-only V2 grant'); }

// The independent SQL oracle selects the single current revision location.
// Historical location rows must never replace or erase the current address.
$base = " FROM {location} l JOIN {location_instance} li ON li.lid=l.lid JOIN {node} n ON n.nid=li.nid
  WHERE n.type='laptop' AND n.status=1 AND CHAR_LENGTH(TRIM(l.country))=2 AND ASCII(UPPER(SUBSTRING(TRIM(l.country),1,1))) BETWEEN 65 AND 90 AND ASCII(UPPER(SUBSTRING(TRIM(l.country),2,1))) BETWEEN 65 AND 90
  AND li.vid=n.vid
  AND (SELECT COUNT(*) FROM {location_instance} locations WHERE locations.nid=n.nid AND locations.vid=n.vid)=1 ";
$columns = 'SELECT n.nid,l.country,l.city,l.postal_code,l.street';
$complete = " AND TRIM(l.city)<>'' AND TRIM(l.street)<>'' AND TRIM(l.postal_code)<>'' ";
$available = $external->query('SELECT UPPER(l.country) AS country, MIN(n.nid) AS nid' . $base . $complete . ' GROUP BY UPPER(l.country)')->fetchAllKeyed();
$selected = [];
foreach (['CH', 'DE', 'ES', 'US', 'FR', 'GB', 'AT', 'CN', 'IN', 'JP'] as $country) {
  if (isset($available[$country])) {
    $row = $external->query($columns . $base . ' AND n.nid=:nid', [':nid' => (int) $available[$country]])->fetchAssoc();
    $selected[] = ['case' => 'complete-' . $country, 'source' => $row];
  }
}
foreach ([
  'missing-postal-code' => " AND TRIM(l.city)<>'' AND TRIM(l.postal_code)='' ",
  'missing-street' => " AND TRIM(l.city)<>'' AND TRIM(l.street)='' AND TRIM(l.postal_code)<>'' ",
] as $name => $condition) {
  $row = $external->query($columns . $base . $condition . ' ORDER BY n.nid LIMIT 1')->fetchAssoc();
  if (!$row) { throw new RuntimeException('No real V2 sample for ' . $name); }
  $selected[] = ['case' => $name, 'source' => $row];
}
$currentBase = " FROM {location} l JOIN {location_instance} li ON li.lid=l.lid JOIN {node} n ON n.nid=li.nid
  WHERE n.type='laptop' AND n.status=1 AND li.vid=n.vid
  AND CHAR_LENGTH(TRIM(l.country))=2 AND ASCII(UPPER(SUBSTRING(TRIM(l.country),1,1))) BETWEEN 65 AND 90 AND ASCII(UPPER(SUBSTRING(TRIM(l.country),2,1))) BETWEEN 65 AND 90
  AND (SELECT COUNT(*) FROM {location_instance} all_locations WHERE all_locations.nid=n.nid)>1
  AND (SELECT COUNT(*) FROM {location_instance} current_locations WHERE current_locations.nid=n.nid AND current_locations.vid=n.vid)=1 ";
$current = $external->query($columns . $currentBase . $complete . ' ORDER BY n.nid LIMIT 1')->fetchAssoc();
if (!$current) { throw new RuntimeException('No real V2 sample with historical locations'); }
$selected[] = ['case' => 'current-revision-with-location-history', 'source' => $current];
if (count($selected) < 6) { throw new RuntimeException('Insufficient real V2 address diversity'); }
$fixtures = json_decode(file_get_contents($state . '/' . $variant . '-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);
\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load($fixtures['accounts']['admin']['id']));
$module = \Drupal::service('extension.list.module')->getPath('labdoo_migrate');
$config = json_decode(file_get_contents(DRUPAL_ROOT . '/' . $module . '/config/fields_mapping/laptop.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$fields = array_values(array_filter($config['fields_mapping'], static fn($field) => $field['destination']['field'] === 'field_address'));
if (count($fields) !== 4) { throw new RuntimeException('Expected all four address mappings'); }
$mapping = \Drupal::service('labdoo_migrate.mapper.fields_mapper')->buildMapping($fields);
$sourceRepository = \Drupal::service('labdoo_migrate.source_content.repository.node');
$destinationRepository = \Drupal::service('labdoo_migrate.destination_content.repository.node');
$destinationRepository->setIndexingMode(FALSE);
$samples = [];
foreach ($selected as $selection) {
  $source = $selection['source'];
  $id = (int) $source['nid'];
  $entities = $sourceRepository->getEntities('laptop', $mapping, [$id]);
  // The source repository must restore the active V3 connection itself.
  require __DIR__ . '/assert-isolated.php';
  $sourceEntity = $entities[$id];
  $language = $sourceEntity['metadata']['main_langcode'];
  $node = Node::load($fixtures['nodes']['Laptop Main']['id'])->createDuplicate();
  $node->set('langcode', $language)->setTitle('E2E V2 address ' . $selection['case']);
  $node->set('field_model', 'E2E V2 address ' . $selection['case']);
  $node->set('field_serial_number', 'E2E-V2-' . $selection['case']);
  $node->set('field_address', NULL);
  if ($node->hasField('field_d7_nid')) { $node->set('field_d7_nid', NULL); }
  $node->skip_geocoding_enqueue = TRUE;
  $node->skip_recompute_enqueue = TRUE;
  $node->labdoo_skip_geocoding = TRUE;
  $node->save();
  if (!$samples && !Node::load($node->id())->get('field_address')->isEmpty()) {
    throw new RuntimeException('Expected a cached node without an address before migration');
  }
  if ($destinationRepository->updateEntities([$id => $sourceEntity], $mapping, [$id => $node]) !== 1) {
    throw new RuntimeException('Migration did not save the real V2 address for ' . $selection['case']);
  }
  $expected = ['country_code' => strtoupper(trim($source['country']))];
  foreach (['city' => 'locality', 'postal_code' => 'postal_code', 'street' => 'address_line1'] as $from => $to) {
    $expected[$to] = trim((string) $source[$from]);
  }
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $storage->resetCache([$node->id()]);
  $saved = $storage->load($node->id());
  $savedAddress = $saved->get('field_address')->first()?->getValue() ?? [];
  foreach ($expected as $component => $value) {
    if (($savedAddress[$component] ?? '') !== $value) {
      $types = [];
      foreach ($sourceEntity[$language] as $key => $sourceValue) { $types[$key] = ['type' => get_debug_type($sourceValue), 'count' => is_array($sourceValue) ? count($sourceValue) : 1]; }
      file_put_contents($state . '/v2-address-mismatch-metadata.json', json_encode(['case' => $selection['case'], 'component' => $component, 'source_types' => $types], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
      throw new RuntimeException('Persisted address mismatch: ' . $selection['case'] . '/' . $component);
    }
  }
  $samples[] = ['case' => $selection['case'], 'source_id' => $id, 'id' => (int) $saved->id(), 'path' => '/' . $language . '/node/' . $saved->id(), 'expected' => $expected];
}
file_put_contents($state . '/legacy-address-fixtures.json', json_encode(['variant' => $variant, 'source_read_only' => TRUE, 'source_database' => 'labdoo_e2e_v2_20261107', 'samples' => $samples], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
chmod($state . '/legacy-address-fixtures.json', 0600);
echo 'Actual source and destination migration repositories persisted ' . count($samples) . ' real V2 address samples.' . PHP_EOL;
