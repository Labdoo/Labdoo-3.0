<?php

/**
 * @file
 * Tests migration reads with real Drupal statements and disposable source data.
 */

declare(strict_types=1);

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\labdoo_migrate\Commands\MissingSequenceImportCommands;
use Drupal\labdoo_migrate\Services\Database\ExternalConnectionManager;
use Drupal\labdoo_migrate\Services\Incremental\UserDependencyImporter;

require __DIR__ . '/e2e/support/assert-isolated.php';
if (realpath(\Drupal::root()) !== realpath(dirname(__DIR__) . '/web')
  || Settings::get('labdoo_e2e_state') !== __DIR__ . '/upgrade/.local/state') {
  throw new RuntimeException('Use this checkout\'s prepared local regression clone.');
}
set_error_handler(static function (int $severity, string $message): bool {
  if ($severity === E_USER_DEPRECATED && (str_contains($message, 'fetchAll()') || str_contains($message, 'fetchAllAssoc()'))) {
    throw new LogicException($message);
  }
  return FALSE;
});

$key = 'migration_fetch_regression';
$options = \Drupal::database()->getConnectionOptions();
$options['prefix'] = 'fetch_regression_' . bin2hex(random_bytes(6)) . '_';
Database::addConnectionInfo($key, 'default', $options);
$source = Database::getConnection('default', $key);
$destination = \Drupal::database();
$tables = [];
$metadataTables = [];
$metadataConnections = [];
$metadata = NULL;
$readKeys = [$key, 'default'];
$passed = [];
$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new LogicException($message);
  }
};
$case = static function (string $name, callable $callback) use (&$passed): void {
  $callback();
  $passed[] = $name;
};
$read = static function (callable $callback) use (&$readKeys, $key, $assert): mixed {
  $name = 'fetch_read_' . bin2hex(random_bytes(4));
  foreach ($readKeys as $connectionKey) {
    Database::startLog($name, $connectionKey);
  }
  try {
    $result = $callback();
  }
  finally {
    foreach ($readKeys as $connectionKey) {
      foreach (Database::getLog($name, $connectionKey) as $query) {
        // The ID guard scopes fresh statistics to its own connection.
        // It restores the prior setting. Persistent writes remain forbidden.
        if ($connectionKey !== $key && in_array($query['query'], [
          'SET SESSION information_schema_stats_expiry = 0',
          'SET SESSION information_schema_stats_expiry = CAST(:expiry AS UNSIGNED)',
        ], TRUE)) {
          continue;
        }
        $assert((bool) preg_match('/^(SELECT|SHOW|DESCRIBE)\b/i', ltrim($query['query'])), 'Migration read performed writable SQL.');
      }
    }
  }
  return $result;
};
try {
  $integer = ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0];
  $string = ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''];
  $schemas = [
    'node' => ['nid' => $integer, 'title' => $string, 'type' => $string, 'status' => $integer],
    'node_field_data' => ['nid' => $integer, 'title' => $string, 'type' => $string, 'status' => $integer],
    'users' => array_fill_keys(['uid', 'status', 'created'], $integer)
      + array_fill_keys(['name', 'mail', 'pass', 'language', 'timezone'], $string),
    'users_roles' => ['uid' => $integer, 'rid' => $integer],
    'field_data_field_user_name' => array_fill_keys(['entity_id', 'deleted', 'delta'], $integer)
      + array_fill_keys(['entity_type', 'language', 'field_user_name_value'], $string),
    'location_instance' => ['lid' => $integer, 'uid' => $integer],
    'location' => ['lid' => $integer, 'latitude' => $string, 'longitude' => $string],
  ];
  foreach ($schemas as $table => $fields) {
    $source->schema()->createTable($table, ['fields' => $fields]);
    $tables[] = $table;
  }
  foreach ([
    [1, '000000001', 'dootronic', 1], [2, '000000003', 'dootronic', 1],
    [3, '000000002', 'dootronic', 0], [4, 'Dootrip #000000002', 'dootrip', 1],
  ] as [$nid, $title, $type, $status]) {
    $source->insert('node_field_data')->fields(compact('nid', 'title', 'type', 'status'))->execute();
  }
  foreach ([
    [11, '000000002', 'laptop', 1], [12, '000000004', 'laptop', 1],
    [13, '000000005', 'laptop', 0], [14, 'Dootrip #000000002', 'dootrip', 1],
  ] as [$nid, $title, $type, $status]) {
    $source->insert('node')->fields(compact('nid', 'title', 'type', 'status'))->execute();
  }
  $commands = new MissingSequenceImportCommands($source, new ExternalConnectionManager());
  $destinationRows = new ReflectionMethod($commands, 'getDestinationTitles');
  $sourceRows = new ReflectionMethod($commands, 'getSourceRows');
  $extract = new ReflectionMethod($commands, 'extractSequenceMap');
  $gaps = new ReflectionMethod($commands, 'buildMissingNumbers');
  $filter = new ReflectionMethod($commands, 'filterSourceByMissingNumbers');
  $case('destination titles remain keyed arrays filtered by bundle and publication', static function () use ($read, $destinationRows, $commands, $assert): void {
    $rows = $read(static fn() => $destinationRows->invoke($commands, 'dootronic'));
    $assert(array_map('intval', array_keys($rows)) === [1, 2], 'Destination title filtering or keys changed.');
    $assert($rows[1]['title'] === '000000001' && count($rows[1]) === 2, 'Destination rows lost their named columns.');
  });
  $case('source titles remain keyed arrays filtered by bundle and publication', static function () use ($read, $sourceRows, $commands, $source, $assert): void {
    $rows = $read(static fn() => $sourceRows->invoke($commands, $source, 'laptop'));
    $assert(array_map('intval', array_keys($rows)) === [11, 12], 'Source title filtering or keys changed.');
    $assert($rows[11]['title'] === '000000002' && count($rows[11]) === 2, 'Source rows lost their named columns.');
  });
  $case('sequence gaps select the matching source node without writes', static function () use ($read, $destinationRows, $sourceRows, $extract, $gaps, $filter, $commands, $source, $assert): void {
    $rows = $read(static fn() => $destinationRows->invoke($commands, 'dootronic'));
    $map = $extract->invoke($commands, 'dootronic', $rows);
    $missing = $gaps->invoke($commands, array_keys($map));
    $candidates = $read(static fn() => $sourceRows->invoke($commands, $source, 'laptop'));
    $matches = $filter->invoke($commands, $extract->invoke($commands, 'dootronic', $candidates), $missing);
    $assert($missing === [2] && array_keys($matches) === [2] && (int) $matches[2]['nid'] === 11, 'Gap selection changed.');
  });
  $case('unknown source and destination bundles keep empty array results', static function () use ($read, $destinationRows, $sourceRows, $commands, $source, $assert): void {
    $assert($read(static fn() => $destinationRows->invoke($commands, 'absent')) === [], 'Unexpected destination result.');
    $assert($read(static fn() => $sourceRows->invoke($commands, $source, 'absent')) === [], 'Unexpected source result.');
  });

  $uid = max(2000000000, (int) $destination->query('SELECT MAX(uid) FROM {users}')->fetchField() + 1);
  $assert($uid < 4294967295, 'No safe synthetic source UID is available.');
  $name = 'fetch-fixture-' . bin2hex(random_bytes(6));
  $source->insert('users')->fields([
    'uid' => $uid, 'name' => $name, 'mail' => $name . '@example.invalid', 'pass' => '',
    'status' => 0, 'created' => 123, 'language' => 'en', 'timezone' => 'UTC',
  ])->execute();
  $source->insert('field_data_field_user_name')->fields([
    'entity_id' => $uid, 'entity_type' => 'user', 'deleted' => 0, 'delta' => 0,
    'language' => 'und', 'field_user_name_value' => 'Synthetic profile',
  ])->execute();
  $source->insert('location_instance')->fields(['uid' => $uid, 'lid' => 1])->execute();
  $source->insert('location')->fields(['lid' => 1, 'latitude' => '40.4168', 'longitude' => '-3.7038'])->execute();
  $importer = new UserDependencyImporter($source, $destination);
  $first = NULL;
  $case('user preview reads profile and locations without leaking private source values', static function () use ($read, $importer, $uid, $name, $assert, &$first): void {
    $first = $read(static fn() => $importer->run([$uid, $uid]));
    $assert($first['dry_run'] && $first['created'] === 0 && count($first['users']) === 1, 'Preview wrote or duplicated a user.');
    $assert($first['users'][$uid]['operation'] === 'create', 'Synthetic account was not a preview candidate.');
    $assert(strlen($first['users'][$uid]['source_hash']) === 64, 'Source fingerprint missing.');
    $json = json_encode($first, JSON_THROW_ON_ERROR);
    $assert(!str_contains($json, $name) && !str_contains($json, 'Synthetic profile'), 'Preview exposed private source values.');
  });
  $case('profile and location edits are reflected in the preview fingerprint', static function () use ($read, $importer, $uid, $source, $assert, &$first): void {
    $source->update('field_data_field_user_name')->fields(['field_user_name_value' => 'Changed synthetic profile'])->execute();
    $profile = $read(static fn() => $importer->run([$uid]));
    $assert($profile['users'][$uid]['source_hash'] !== $first['users'][$uid]['source_hash'], 'Profile change was not fingerprinted.');
    $source->update('location')->fields(['latitude' => '41.0'])->execute();
    $location = $read(static fn() => $importer->run([$uid]));
    $assert($location['users'][$uid]['source_hash'] !== $profile['users'][$uid]['source_hash'], 'Location change was not fingerprinted.');
  });
  $case('preview role policy still blocks source administrator privileges', static function () use ($read, $importer, $uid, $source, $assert): void {
    $source->insert('users_roles')->fields(['uid' => $uid, 'rid' => 3])->execute();
    $default = $read(static fn() => $importer->run([$uid]));
    $explicit = $read(static fn() => $importer->run([$uid], FALSE, TRUE));
    $assert(in_array('special_roles_require_explicit_user_migration', $default['users'][$uid]['reasons'], TRUE), 'Default role policy changed.');
    $assert(in_array('administrator_source_role_forbidden', $explicit['users'][$uid]['reasons'], TRUE), 'Administrator role became a candidate.');
  });
  $case('node invariant reads retain associative metadata and membership rows', static function () use ($read, $importer, $destination, $assert): void {
    $assert($destination->schema()->tableExists('node__field_team_members'), 'The clone lacks team membership fixtures.');
    $method = new ReflectionMethod($importer, 'nodeInvariants');
    $expiry = $destination->query('SELECT @@SESSION.information_schema_stats_expiry')->fetchField();
    $invariants = $read(static fn() => $method->invoke($importer));
    $assert($destination->query('SELECT @@SESSION.information_schema_stats_expiry')->fetchField() === $expiry, 'Metadata guard changed the caller statistics setting.');
    $assert(count($invariants['auto_increment']) === 2 && is_array($invariants['global_team_members']), sprintf('Invariant result shape changed: %d metadata rows.', count($invariants['auto_increment'])));
    foreach ($invariants['auto_increment'] as $row) {
      $assert(array_key_exists('TABLE_NAME', $row) && array_key_exists('AUTO_INCREMENT', $row), 'Invariant columns changed.');
    }
  });
  $case('missing source user remains blocked without creating an account', static function () use ($read, $importer, $uid, $destination, $assert): void {
    $result = $read(static fn() => $importer->run([$uid + 1]));
    $assert($result['blocked'] === 1 && in_array('source_user_missing', $result['users'][$uid + 1]['reasons'], TRUE), 'Missing user policy changed.');
    $assert((int) $destination->select('users', 'u')->condition('uid', [$uid, $uid + 1], 'IN')->countQuery()->execute()->fetchField() === 0, 'Preview created users.');
  });

  $metadataOptions = $destination->getConnectionOptions();
  $metadataPrefix = 'metadata_regression_' . bin2hex(random_bytes(5)) . '_';
  foreach ([$metadataPrefix, $metadataOptions['database'] . '.' . $metadataPrefix] as $index => $prefix) {
    $connectionKey = 'migration_metadata_' . $index;
    $metadataOptions['prefix'] = $prefix;
    Database::addConnectionInfo($connectionKey, 'default', $metadataOptions);
    $metadataConnections[] = $connectionKey;
    $readKeys[] = $connectionKey;
  }
  $metadata = Database::getConnection('default', $metadataConnections[0]);
  foreach (['node' => 'nid', 'node_revision' => 'vid'] as $table => $id) {
    $metadata->schema()->createTable($table, [
      'fields' => [$id => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE]],
      'primary key' => [$id],
    ]);
    $metadataTables[] = $table;
  }
  $case('prefixed and database-qualified metadata detect sequence drift without row changes', static function () use ($read, $source, $destination, $metadataConnections, $metadataPrefix, $assert): void {
    $container = \Drupal::getContainer();
    try {
      foreach ($metadataConnections as $index => $connectionKey) {
        $connection = Database::getConnection('default', $connectionKey);
        $container->set('database', $connection);
        $guard = new UserDependencyImporter($source, $connection);
        $method = new ReflectionMethod($guard, 'nodeInvariants');
        $expiry = $connection->query('SELECT @@SESSION.information_schema_stats_expiry')->fetchField();
        $before = $read(static fn() => $method->invoke($guard));
        $expectedTables = [
          $metadataPrefix . 'node',
          $metadataPrefix . 'node_revision',
        ];
        $assert(array_column($before['auto_increment'], 'TABLE_NAME') === $expectedTables, 'Prefixed metadata names were not resolved.');
        // Allocate and remove a synthetic row: only the sequence should differ.
        $id = ($index + 1) * 10;
        $connection->insert('node')->fields(['nid' => $id])->execute();
        $connection->delete('node')->condition('nid', $id)->execute();
        $after = $read(static fn() => $method->invoke($guard));
        $assert($connection->query('SELECT @@SESSION.information_schema_stats_expiry')->fetchField() === $expiry, 'Prefixed guard changed the caller statistics setting.');
        $assert($after['node_count'] === $before['node_count'] && $after['max_nid'] === $before['max_nid'], 'Synthetic row cleanup failed.');
        $assert($after['auto_increment'] !== $before['auto_increment'], sprintf('Auto-increment drift was invisible for prefix variant %d: %s -> %s.', $index, json_encode($before['auto_increment'], JSON_THROW_ON_ERROR), json_encode($after['auto_increment'], JSON_THROW_ON_ERROR)));
      }
    }
    finally {
      $container->set('database', $destination);
    }
  });
  echo json_encode(['passed' => count($passed), 'cases' => $passed], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
}
finally {
  foreach (array_reverse($metadataTables) as $table) {
    $metadata->schema()->dropTable($table);
  }
  foreach ($metadataConnections as $connectionKey) {
    Database::removeConnection($connectionKey);
  }
  foreach (array_reverse($tables) as $table) {
    $source->schema()->dropTable($table);
  }
  Database::removeConnection($key);
  restore_error_handler();
}
