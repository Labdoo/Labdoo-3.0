<?php

/**
 * @file
 * Regression checks for migration state cleanup, without migrating entities.
 *
 * Run with Drush php:script on an isolated labdoo_e2e_* database only.
 */

use Drupal\Core\Database\Database;
use Drupal\labdoo_migrate\Commands\SynchronizerCommands;
use Drupal\labdoo_migrate\Plugin\QueueWorker\MigrationQueueWorkerBase;
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepository;
use Drupal\labdoo_migrate\Services\SourceContent\SourceRepository;
use Drupal\Core\Site\Settings;
use Psr\Log\NullLogger;

if (!Settings::get('labdoo_e2e') || !str_starts_with(Database::getConnection()->getConnectionOptions()['database'], 'labdoo_e2e_')) {
  throw new RuntimeException('An isolated E2E database is required.');
}

$command = new class extends SynchronizerCommands {
  public function __construct() {
    $this->setLogger(new NullLogger());
  }

  protected function setEnvironment(string $contentType, array $options): void {
    \Drupal::state()->set('labdoo_migrate_is_running', TRUE);
    \Drupal::state()->set('labdoo_migrate.disable_indexing', TRUE);
    throw new RuntimeException('Expected command failure.');
  }
};

$source = new class extends SourceRepository {
  public function __construct() {}

  public function getEntity(string $contentType, array $mapping, int $entityId, ?int $fromTimestamp = NULL): array {
    return ['nid' => $entityId];
  }
};

$destination = new class extends DestinationRepository {
  public bool $fail = FALSE;

  public function __construct() {}

  public function createEntities(array $sourceEntities, array $mapping, string $contentType, bool $dryRun = FALSE): int {
    if (\Drupal::state()->get('labdoo_migrate_is_running') !== TRUE) {
      throw new LogicException('Migration flag was not enabled during processing.');
    }
    if ($this->fail) {
      throw new RuntimeException('Expected worker failure.');
    }
    return 1;
  }
};

$worker = new class($source, $destination) extends MigrationQueueWorkerBase {
  public function __construct($source, $destination) {
    $this->logger = \Drupal::logger('migration_recovery_test');
    $this->nodeSourceRepository = $source;
    $this->nodeDestinationRepository = $destination;
  }
};

$state = \Drupal::state();
$keys = ['labdoo_migrate_is_running', 'labdoo_migrate.disable_indexing'];
$original = [];
foreach ($keys as $key) {
  $original[$key] = $state->get($key);
}
$active = Database::getConnection()->getKey();
$cases = 0;
try {
  foreach ([NULL, FALSE, TRUE] as $previous) {
    foreach ($keys as $key) {
      $previous === NULL ? $state->delete($key) : $state->set($key, $previous);
    }
    try {
      $command->startSync('dootronic');
      throw new LogicException('Command failure must propagate.');
    }
    catch (RuntimeException $e) {
      if ($e->getMessage() !== 'Expected command failure.') {
        throw $e;
      }
    }
    foreach ($keys as $key) {
      if ($state->get($key) !== $previous) {
        throw new LogicException('Command did not restore ' . $key);
      }
    }
    $cases++;
    foreach ([FALSE, TRUE] as $fail) {
      $destination->fail = $fail;
      try {
        $worker->processItem([
          'queue_id' => 'labdoo_migrate_migration_laptop',
          'timestamp' => new DateTime(),
          'data' => ['content_type' => 'laptop', 'entity_id' => 1, 'mapping' => []],
        ]);
        if ($fail) {
          throw new LogicException('Worker failure must propagate.');
        }
      }
      catch (RuntimeException $e) {
        if (!$fail || $e->getMessage() !== 'Expected worker failure.') {
          throw $e;
        }
      }
      if ($state->get('labdoo_migrate_is_running') !== $previous || Database::getConnection()->getKey() !== $active) {
        throw new LogicException('Worker did not restore its execution environment.');
      }
      $cases++;
    }
  }
}
finally {
  foreach ($original as $key => $value) {
    $value === NULL ? $state->delete($key) : $state->set($key, $value);
  }
  Database::setActiveConnection($active);
}
echo json_encode(['passed' => $cases, 'entity_writes' => 0]) . PHP_EOL;
