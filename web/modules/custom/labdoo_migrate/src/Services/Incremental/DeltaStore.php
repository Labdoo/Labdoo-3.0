<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Database\Connection;

/** Durable manifests and successful baselines; never stores source content. */
final class DeltaStore {
  public const RUN = 'labdoo_migrate_delta_run';
  public const ITEM = 'labdoo_migrate_delta_item';
  public const SNAPSHOT = 'labdoo_migrate_delta_snapshot';

  public function __construct(private Connection $database) {}

  public static function schema(): array {
    $id = ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0];
    $text = ['type' => 'text', 'size' => 'big', 'not null' => TRUE];
    $hash = ['type' => 'varchar', 'length' => 64, 'not null' => TRUE, 'default' => ''];
    $type = ['type' => 'varchar', 'length' => 64, 'not null' => TRUE];
    $run = ['type' => 'varchar', 'length' => 32, 'not null' => TRUE];
    $tables[self::RUN] = ['description' => 'Resumable source inventory and execution manifests.', 'fields' => [
      'run_id' => $run, 'source_type' => $type, 'bundle' => $type, 'mapping_hash' => $hash,
      'source_identity' => $hash, 'phase' => ['type' => 'varchar', 'length' => 24, 'not null' => TRUE],
      'cursor' => $id, 'deletion_cursor' => $id, 'ceiling' => $id,
      'created' => $id, 'updated' => $id, 'metadata' => $text,
    ], 'primary key' => ['run_id'], 'indexes' => ['source_phase' => ['source_type', 'phase']]];
    $tables[self::ITEM] = ['description' => 'One guarded delta operation per source root in a manifest.', 'fields' => [
      'run_id' => $run, 'source_id' => $id, 'destination_id' => $id,
      'operation' => ['type' => 'varchar', 'length' => 24, 'not null' => TRUE],
      'status' => ['type' => 'varchar', 'length' => 16, 'not null' => TRUE, 'default' => 'pending'],
      'source_hash' => $hash, 'destination_hash' => $hash, 'source_languages' => $text, 'details' => $text,
      'attempts' => $id, 'updated' => $id,
    ], 'primary key' => ['run_id', 'source_id'], 'indexes' => ['work' => ['run_id', 'status', 'source_id']]];
    $tables[self::SNAPSHOT] = ['description' => 'Source and destination fingerprints confirmed together after success.', 'fields' => [
      'source_type' => $type, 'source_id' => $id, 'destination_id' => $id,
      'source_hash' => $hash, 'destination_hash' => $hash, 'mapping_hash' => $hash,
      'source_identity' => $hash, 'source_languages' => $text, 'observed' => $id,
    ], 'primary key' => ['source_type', 'source_id'], 'indexes' => ['destination' => ['destination_id']]];
    return $tables;
  }

  public function assertInstalled(): void {
    foreach (array_keys(self::schema()) as $table) {
      if (!$this->database->schema()->tableExists($table)) {
        throw new \RuntimeException('Incremental metadata schema is missing. Run reviewed database updates before planning.');
      }
    }
  }

  public function transaction() { return $this->database->startTransaction(); }

  public function createRun(array $record): array {
    $this->assertInstalled();
    $record += ['run_id' => bin2hex(random_bytes(16)), 'phase' => 'scan', 'cursor' => 0, 'deletion_cursor' => 0, 'created' => time(), 'updated' => time(), 'metadata' => '{}'];
    $this->database->insert(self::RUN)->fields($record)->execute();
    return $record;
  }

  public function run(string $id): array {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
      throw new \InvalidArgumentException('A full manifest ID is required.');
    }
    $record = $this->database->select(self::RUN, 'r')->fields('r')->condition('run_id', $id)->execute()->fetchAssoc();
    if (!$record) { throw new \RuntimeException('Unknown manifest.'); }
    return $record;
  }

  public function updateRun(string $id, array $values): void {
    $this->database->update(self::RUN)->fields($values + ['updated' => time()])->condition('run_id', $id)->execute();
  }

  public function plannedBatch(array $run, array $items, array $checkpoint): void {
    $transaction = $this->transaction();
    try {
      if ($items) {
        $query = $this->database->insert(self::ITEM)->fields(['run_id', 'source_id', 'destination_id', 'operation', 'status', 'source_hash', 'destination_hash', 'source_languages', 'details', 'attempts', 'updated']);
        foreach ($items as $item) {
          $query->values([$run['run_id'], $item['source_id'], $item['destination_id'] ?? 0, $item['operation'], 'pending', $item['source_hash'] ?? '', $item['destination_hash'] ?? '', json_encode($item['source_languages'] ?? [], JSON_THROW_ON_ERROR), json_encode($item['details'] ?? [], JSON_THROW_ON_ERROR), 0, time()]);
        }
        $query->execute();
      }
      $this->updateRun($run['run_id'], $checkpoint);
      unset($transaction);
    }
    catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
  }

  public function baselines(string $type, array $ids): array {
    if (!$ids || !$this->database->schema()->tableExists(self::SNAPSHOT)) { return []; }
    return $this->database->select(self::SNAPSHOT, 's')->fields('s')->condition('source_type', $type)->condition('source_id', $ids, 'IN')->execute()->fetchAllAssoc('source_id', \PDO::FETCH_ASSOC);
  }

  public function baseline(array $run, array $item, int $destinationId, string $destinationHash): void {
    $this->database->merge(self::SNAPSHOT)->keys(['source_type' => $run['source_type'], 'source_id' => $item['source_id']])->fields([
      'destination_id' => $destinationId, 'source_hash' => $item['source_hash'], 'destination_hash' => $destinationHash,
      'mapping_hash' => $run['mapping_hash'], 'source_identity' => $run['source_identity'], 'source_languages' => $item['source_languages'] ?? '[]', 'observed' => time(),
    ])->execute();
  }

  public function items(string $runId, int $limit, bool $sourceWins = FALSE, bool $retryErrors = FALSE, bool $unpublish = FALSE, int $after = 0, bool $retryDependencies = FALSE): array {
    $query = $this->database->select(self::ITEM, 'i')->fields('i')->condition('run_id', $runId)->condition('source_id', $after, '>');
    $eligible = $query->orConditionGroup()->condition('status', 'pending');
    if ($sourceWins) { $eligible->condition($query->andConditionGroup()->condition('status', 'blocked')->condition('operation', ['update_candidate', 'deleted'], 'IN')); }
    if ($retryErrors) { $eligible->condition('status', 'error'); }
    if ($retryDependencies) { $eligible->condition($query->andConditionGroup()->condition('operation', 'create_staged')->condition('destination_id', 0, '>')->condition('status', ['waiting_refs', 'error'], 'IN')); }
    if ($unpublish) { $eligible->condition($query->andConditionGroup()->condition('status', 'reported')->condition('operation', 'deleted')); }
    return $query->condition($eligible)->orderBy('source_id')->range(0, $limit)->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /** Unfinished owner creation must not be hidden by adoption in a new run. */
  public function pendingDependencies(string $type, array $ids): array {
    if (!$ids || !$this->database->schema()->tableExists(self::ITEM)) { return []; }
    $query = $this->database->select(self::ITEM, 'i');
    $query->innerJoin(self::RUN, 'r', 'r.run_id=i.run_id');
    $query->fields('i')->condition('r.source_type', $type)->condition('i.source_id', $ids, 'IN')->condition('i.operation', 'create_staged')->condition('i.destination_id', 0, '>')->condition('i.status', ['waiting_refs', 'error'], 'IN')->orderBy('r.created', 'DESC');
    $out = [];
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $item) { $out[(int) $item['source_id']] ??= $item; }
    return $out;
  }

  /** Save the created owner's identity and its unresolved dependency atomically. */
  public function waitingReferences(array $item, array $result, array $dependency): void {
    $details = array_replace(json_decode($item['details'], TRUE, 512, JSON_THROW_ON_ERROR), [
      'reason' => 'awaiting_dependencies', 'awaiting_dependencies' => TRUE,
      'dependencies' => $dependency, 'destination_hash_after_create' => $result['destination_hash'],
      'effects' => $result['effects'] ?? [], 'written' => TRUE,
    ]);
    $this->database->update(self::ITEM)->fields([
      'status' => 'waiting_refs', 'destination_id' => $result['destination_id'], 'destination_hash' => $result['destination_hash'],
      'details' => json_encode($details, JSON_THROW_ON_ERROR), 'updated' => time(),
    ])->expression('attempts', 'attempts + 1')->condition('run_id', $item['run_id'])->condition('source_id', $item['source_id'])->execute();
  }

  /** Metadata-only adoption is one upsert per batch, never a node save. */
  public function baselineBatch(array $run, array $items): void {
    if (!$items) { return; }
    $query = $this->database->upsert(self::SNAPSHOT)->key('source_id')->fields(['source_type', 'source_id', 'destination_id', 'source_hash', 'destination_hash', 'mapping_hash', 'source_identity', 'source_languages', 'observed']);
    foreach ($items as $item) {
      $query->values([$run['source_type'], $item['source_id'], $item['destination_id'], $item['source_hash'], $item['destination_hash'], $run['mapping_hash'], $run['source_identity'], $item['source_languages'] ?? '[]', time()]);
    }
    $query->execute();
  }

  public function batchStatus(string $runId, array $ids, string $status): void {
    if (!$ids) { return; }
    $this->database->update(self::ITEM)->fields(['status' => $status, 'updated' => time()])->expression('attempts', 'attempts + 1')->condition('run_id', $runId)->condition('source_id', $ids, 'IN')->execute();
  }

  public function itemStatus(array $item, string $status, array $details = []): void {
    $this->database->update(self::ITEM)->fields(['status' => $status, 'attempts' => (int) $item['attempts'] + 1, 'details' => json_encode(array_replace(json_decode($item['details'], TRUE, 512, JSON_THROW_ON_ERROR), $details), JSON_THROW_ON_ERROR), 'updated' => time()])->condition('run_id', $item['run_id'])->condition('source_id', $item['source_id'])->execute();
  }

  public function listItems(string $runId, int $after = 0, int $limit = 100, ?string $operation = NULL, ?string $status = NULL): array {
    $this->run($runId);
    if ($after < 0 || $limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Use non-negative after and limit 1..500.'); }
    $query = $this->database->select(self::ITEM, 'i')->fields('i')->condition('run_id', $runId)->condition('source_id', $after, '>')->orderBy('source_id')->range(0, $limit);
    foreach (['operation' => $operation, 'status' => $status] as $key => $value) {
      if ($value !== NULL) { $query->condition($key, $value); }
    }
    $rows = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($rows as &$row) { $row['details'] = json_decode($row['details'], TRUE, 512, JSON_THROW_ON_ERROR); }
    return $rows;
  }

  public function summary(string $runId): array {
    $run = $this->run($runId);
    $query = $this->database->select(self::ITEM, 'i');
    $query->fields('i', ['operation', 'status'])->condition('run_id', $runId)->groupBy('operation')->groupBy('status');
    $query->addExpression('COUNT(*)', 'count');
    $counts = [];
    foreach ($query->execute() as $row) { $counts[$row->operation][$row->status] = (int) $row->count; }
    return ['run_id' => $runId, 'source_type' => $run['source_type'], 'phase' => $run['phase'], 'cursor' => (int) $run['cursor'], 'deletion_cursor' => (int) $run['deletion_cursor'], 'source_ceiling' => (int) $run['ceiling'], 'source_roots_at_start' => json_decode($run['metadata'], TRUE)['source_root_count'] ?? NULL, 'operations' => $counts];
  }
}
