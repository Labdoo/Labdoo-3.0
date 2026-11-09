<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Lock\LockBackendInterface;

/** Plans bounded deltas and applies only guarded operations, with atomic progress. */
final class DeltaEngine {
  public function __construct(private object $source, private object $adapter, private DeltaStore $store, private LockBackendInterface $lock, private string $sourceIdentity) {}

  private function settings(array $options): array {
    $options += ['batch_size' => 100, 'max_items' => 0, 'run_id' => NULL, 'dry_run' => FALSE, 'source_wins' => FALSE, 'missing_source' => 'report', 'retry_errors' => FALSE, 'confirm_mass_unpublish' => FALSE];
    if ($options['batch_size'] < 1 || $options['batch_size'] > 500 || $options['max_items'] < 0 || !in_array($options['missing_source'], ['report', 'unpublish'], TRUE)) {
      throw new \InvalidArgumentException('Use batch size 1..500, non-negative max-items and missing-source=report|unpublish.');
    }
    return $options;
  }

  private function acquire(string $type): string {
    if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $type)) { throw new \InvalidArgumentException('Invalid source type.'); }
    if (\Drupal::hasContainer() && \Drupal::state()->get('labdoo_migrate_is_running', FALSE)) { throw new \RuntimeException('A legacy migration is active; do not run concurrent writers.'); }
    $name = 'labdoo_migrate.delta';
    if (!$this->lock->acquire($name, 300)) { throw new \RuntimeException('Another incremental operation holds this source type lock.'); }
    $GLOBALS['labdoo_incremental_lock_owner'] = TRUE;
    return $name;
  }

  private function compatible(array $run, array $context, string $type): void {
    if ($run['source_type'] !== $type || $run['source_identity'] !== $this->sourceIdentity || $run['mapping_hash'] !== $context['mapping_hash'] || $run['bundle'] !== $context['bundle']) {
      throw new \RuntimeException('Source connection, mapping or bundle changed; create a new manifest.');
    }
  }

  public function plan(string $type, array $options = []): array {
    $started = microtime(TRUE);
    if (method_exists($this->adapter, 'resetRuntime')) { $this->adapter->resetRuntime(); }
    $options = $this->settings($options);
    $name = $options['dry_run'] ? NULL : $this->acquire($type);
    try {
      $context = $this->adapter->context($type);
      if ($options['run_id']) {
        if ($options['dry_run']) { throw new \InvalidArgumentException('Preview does not resume or modify stored manifests.'); }
        $run = $this->store->run($options['run_id']);
        $this->compatible($run, $context, $type);
      }
      else {
        $run = ['source_type' => $type, 'bundle' => $context['bundle'], 'mapping_hash' => $context['mapping_hash'], 'source_identity' => $this->sourceIdentity, 'ceiling' => $this->source->maxId($type)];
        $count = method_exists($this->source, 'rootCount') ? $this->source->rootCount($type, (int) $run['ceiling']) : NULL;
        $run['metadata'] = json_encode(['source_root_count' => $count], JSON_THROW_ON_ERROR);
        $run = $options['dry_run'] ? $run + ['run_id' => NULL, 'phase' => 'scan', 'cursor' => 0, 'deletion_cursor' => 0] : $this->store->createRun($run);
      }
      $processed = 0;
      $preview = [];
      while (in_array($run['phase'], ['scan', 'deletions'], TRUE)) {
        if ($name !== NULL && !$this->lock->acquire($name, 300)) { throw new \RuntimeException('Incremental lock lease lost. Resume after reviewing the active writer.'); }
        if ($options['max_items'] && $processed >= $options['max_items']) { break; }
        $limit = $options['max_items'] ? min($options['batch_size'], $options['max_items'] - $processed) : $options['batch_size'];
        if ($run['phase'] === 'scan') {
          $page = $this->source->page($type, (int) $run['cursor'], $limit, (int) $run['ceiling']);
          $ids = array_keys($page);
          if (!$ids) {
            $initialCount = json_decode($run['metadata'], TRUE)['source_root_count'] ?? NULL;
            if ($initialCount !== NULL && $this->source->rootCount($type, (int) $run['ceiling']) !== $initialCount) { throw new \RuntimeException('Source membership changed during inventory; replan from a consistent source.'); }
            $checkpoint = ['phase' => 'deletions'];
            $items = [];
          }
          else {
            $fingerprints = $this->source->fingerprints($type, $ids);
            $destinations = $this->adapter->destinations($type, $ids);
            $baselines = $this->store->baselines($type, $ids);
            $items = [];
            foreach ($ids as $id) {
              if (!isset($fingerprints[$id])) {
                throw new \RuntimeException('Source changed during inventory; resume to read this batch again.');
              }
              $items[] = $this->classify($run, (int) $id, $fingerprints[$id], $destinations[$id] ?? ['id' => NULL, 'hash' => NULL], $baselines[$id] ?? NULL);
            }
            $checkpoint = ['cursor' => (int) max($ids)];
          }
        }
        else {
          $page = $this->adapter->trackedPage($type, (int) $run['deletion_cursor'], $limit);
          $ids = array_keys($page);
          $items = [];
          if (!$ids) { $checkpoint = ['phase' => 'planned']; }
          else {
            $existing = $this->source->existing($ids);
            $rootIds = method_exists($this->source, 'rootIds') ? $this->source->rootIds($ids) : array_keys($existing);
            $deletedBaselines = $this->store->baselines($type, $ids);
            foreach ($page as $id => $destination) {
              // Retyping and roots becoming translations are conflicts, not deletion.
              if (!isset($existing[$id])) {
                $baseline = $deletedBaselines[$id] ?? NULL;
                $validBaseline = $baseline && $baseline['source_identity'] === $run['source_identity'] && $baseline['mapping_hash'] === $run['mapping_hash'] && (int) $baseline['destination_id'] === (int) ($destination['id'] ?? 0);
                $conflict = $destination['conflict'] ?? NULL;
                if ($validBaseline && $baseline['destination_hash'] !== ($destination['hash'] ?? '')) { $conflict = 'destination_changed_before_source_deletion'; }
                $items[] = ['source_id' => (int) $id, 'destination_id' => $destination['id'] ?? 0, 'operation' => $conflict ? 'conflict' : 'deleted', 'destination_hash' => $destination['hash'] ?? '', 'details' => ['reason' => $conflict ?? 'absent_from_source', 'requires_source_wins' => !$validBaseline, 'source_languages_before' => $baseline ? json_decode($baseline['source_languages'] ?? '[]', TRUE) : []]];
              }
              elseif ($existing[$id] !== $type || !in_array((int) $id, $rootIds, TRUE)) {
                $items[] = ['source_id' => (int) $id, 'destination_id' => $destination['id'] ?? 0, 'operation' => 'conflict', 'destination_hash' => $destination['hash'] ?? '', 'details' => ['reason' => 'source_type_or_root_membership_changed']];
              }
            }
            $checkpoint = ['deletion_cursor' => (int) max($ids)];
          }
        }
        if ($options['dry_run']) {
          foreach ($items as $item) { $preview[$item['operation']] = ($preview[$item['operation']] ?? 0) + 1; }
        }
        else { $this->store->plannedBatch($run, $items, $checkpoint); }
        $run = array_replace($run, $checkpoint);
        $processed += count($ids);
        $this->clearRuntime();
      }
      $result = $options['dry_run'] ? ['read_only' => TRUE, 'source_type' => $type, 'phase' => $run['phase'], 'partial' => $run['phase'] !== 'planned', 'operations' => $preview] : $this->store->summary($run['run_id']);
      return $result + ['scanned_this_call' => $processed, 'seconds' => round(microtime(TRUE) - $started, 3), 'peak_memory_bytes' => memory_get_peak_usage(TRUE)];
    }
    finally { if ($name !== NULL) { $this->lock->release($name); unset($GLOBALS['labdoo_incremental_lock_owner']); } }
  }

  private function classify(array $run, int $id, array $source, array $destination, ?array $baseline): array {
    $item = ['source_id' => $id, 'destination_id' => $destination['id'] ?? 0, 'source_hash' => $source['hash'], 'destination_hash' => $destination['hash'] ?? '', 'source_languages' => array_values(array_unique(array_map(static fn($lang) => $lang && $lang !== 'und' ? $lang : (\Drupal::hasContainer() ? \Drupal::languageManager()->getDefaultLanguage()->getId() : 'en'), $source['languages'] ?? [$source['language'] ?? 'en']))), 'details' => ['source_languages_before' => $baseline ? json_decode($baseline['source_languages'] ?? '[]', TRUE, 512, JSON_THROW_ON_ERROR) : []]];
    if (!empty($destination['conflict'])) {
      return array_replace($item, ['operation' => 'conflict', 'details' => ['reason' => $destination['conflict']]]);
    }
    if (empty($destination['id'])) {
      // Surface missing dependencies/configuration in preview, before apply.
      if (!$baseline && method_exists($this->adapter, 'validateNew')) {
        $validation = $this->adapter->validateNew($run['source_type'], $id);
        if (!empty($validation['unsupported'])) {
          return array_replace($item, ['operation' => 'unsupported', 'details' => ['reason' => 'unverifiable_projection', 'fields' => $validation['unsupported']]]);
        }
      }
      // Never silently recreate locally removed migrated nodes.
      return array_replace($item, ['operation' => $baseline ? 'conflict' : 'create', 'details' => ['reason' => $baseline ? 'destination_removed_since_baseline' : 'new_source']]);
    }
    $validBaseline = $baseline && $baseline['mapping_hash'] === $run['mapping_hash'] && $baseline['source_identity'] === $run['source_identity'] && (int) $baseline['destination_id'] === (int) $destination['id'];
    if ($validBaseline) {
      $sourceChanged = !hash_equals($baseline['source_hash'], $source['hash']);
      $destinationChanged = !hash_equals($baseline['destination_hash'], $destination['hash']);
      if (!$sourceChanged) {
        return array_replace($item, ['operation' => $destinationChanged ? 'local_change' : 'unchanged']);
      }
      $sourceOnly = !$destinationChanged;
    }
    $comparison = $this->adapter->compare($run['source_type'], $id, (int) $destination['id'], $item['details']['source_languages_before']);
    if (!empty($comparison['unsupported'])) {
      return array_replace($item, ['operation' => 'unsupported', 'details' => ['reason' => 'unverifiable_projection', 'fields' => $comparison['unsupported']]]);
    }
    if ($comparison['equal']) { return array_replace($item, ['operation' => 'baseline']); }
    if (!empty($sourceOnly)) { return array_replace($item, ['operation' => 'update']); }
    return array_replace($item, ['operation' => $validBaseline ? 'conflict' : 'update_candidate', 'details' => ['reason' => $validBaseline ? 'both_sides_changed' : 'unbaselined_difference', 'source_languages_before' => $item['details']['source_languages_before'], 'fields' => $comparison['differences'] ?? []]]);
  }

  public function apply(string $runId, array $options = []): array {
    $options = $this->settings($options);
    if ($options['dry_run']) { throw new \InvalidArgumentException('Use preview to avoid all content and metadata writes.'); }
    $run = $this->store->run($runId);
    if (method_exists($this->adapter, 'resetRuntime')) { $this->adapter->resetRuntime(); }
    $name = $this->acquire($run['source_type']);
    try {
      $this->compatible($run, $this->adapter->context($run['source_type']), $run['source_type']);
      if (!in_array($run['phase'], ['planned', 'applying', 'review', 'complete'], TRUE)) {
        throw new \RuntimeException('Finish the complete inventory before applying; partial scans cannot establish deletions.');
      }
      if ($options['missing_source'] === 'unpublish' && !$options['confirm_mass_unpublish']) {
        $counts = $this->store->summary($runId)['operations'];
        $deletedCounts = $counts['deleted'] ?? [];
        $deleted = array_sum(array_intersect_key($deletedCounts, array_fill_keys(['pending', 'blocked', 'error', 'reported'], TRUE)));
        $total = array_sum(array_map('array_sum', $counts));
        $sourceCount = json_decode($run['metadata'], TRUE)['source_root_count'] ?? NULL;
        if ($deleted > max(10, (int) ceil($total * 0.05)) || ($deleted > 0 && $sourceCount === 0)) {
          throw new \RuntimeException('Empty source or mass deletion detected. Verify the source is complete; explicit --confirm-mass-unpublish is required.');
        }
      }
      $this->store->updateRun($runId, ['phase' => 'applying']);
      $processed = 0;
      $after = 0;
      while (!$options['max_items'] || $processed < $options['max_items']) {
        if (!$this->lock->acquire($name, 300)) { throw new \RuntimeException('Incremental lock lease lost.'); }
        $limit = $options['max_items'] ? min($options['batch_size'], $options['max_items'] - $processed) : $options['batch_size'];
        $items = $this->store->items($runId, $limit, $options['source_wins'], $options['retry_errors'], $options['missing_source'] === 'unpublish', $after);
        if (!$items) { break; }
        $activeItems = [];
        $baselineItems = [];
        $passive = [];
        foreach ($items as $item) {
          $status = match ($item['operation']) {
            'unchanged' => 'skipped',
            'local_change' => 'preserved',
            'unsupported', 'conflict' => 'blocked',
            'update_candidate' => $options['source_wins'] ? NULL : 'blocked',
            'deleted' => $options['missing_source'] === 'report' ? 'reported' : NULL,
            default => NULL,
          };
          if ($item['operation'] === 'baseline') { $baselineItems[] = $item; }
          elseif ($status !== NULL) { $passive[$status][] = (int) $item['source_id']; }
          else { $activeItems[] = $item; }
        }
        foreach ($passive as $status => $ids) { $this->store->batchStatus($runId, $ids, $status); $processed += count($ids); }
        if ($baselineItems) { $this->adoptBatch($run, $baselineItems); $processed += count($baselineItems); }
        foreach ($activeItems as $item) {
          if (!$this->lock->acquire($name, 300)) { throw new \RuntimeException('Incremental lock lease lost.'); }
          $transaction = $this->store->transaction();
          try {
            $this->applyItem($run, $item, $options);
            unset($transaction);
          }
          catch (\Throwable $e) {
            $transaction->rollBack();
            $this->store->itemStatus($item, 'error', ['reason' => 'apply_failed', 'error_class' => get_class($e)]);
            $this->store->updateRun($runId, ['phase' => 'review']);
            throw new \RuntimeException('Incremental item ' . $item['source_id'] . ' failed; its checkpoint was not advanced. ' . $e->getMessage(), 0, $e);
          }
          $processed++;
          $after = (int) $item['source_id'];
        }
        $after = max(array_map(static fn($item) => (int) $item['source_id'], $items));
        $this->clearRuntime();
      }
      $summary = $this->store->summary($runId);
      $pending = $blocked = 0;
      foreach ($summary['operations'] as $counts) { $pending += $counts['pending'] ?? 0; $blocked += ($counts['blocked'] ?? 0) + ($counts['error'] ?? 0); }
      $this->store->updateRun($runId, ['phase' => $pending ? 'applying' : ($blocked ? 'review' : 'complete')]);
      return $this->store->summary($runId) + ['processed_this_call' => $processed];
    }
    finally { if ($name !== NULL) { $this->lock->release($name); unset($GLOBALS['labdoo_incremental_lock_owner']); } }
  }

  /** Adoption is batched; every unchanged repeat needs no source re-read or node save. */
  private function adoptBatch(array $run, array $items): void {
    if (method_exists($this->adapter, 'resetRuntime')) { $this->adapter->resetRuntime(); }
    $ids = array_map(static fn($item) => (int) $item['source_id'], $items);
    $currentItem = $items[0];
    $transaction = NULL;
    try {
      $source = $this->source->fingerprints($run['source_type'], $ids);
      $destinations = $this->adapter->destinations($run['source_type'], $ids);
      foreach ($items as $item) {
        $currentItem = $item;
        $id = (int) $item['source_id'];
        if (!isset($source[$id]) || $source[$id]['hash'] !== $item['source_hash']) { throw new \RuntimeException('Source changed after planning. Replan.'); }
        $destination = $destinations[$id] ?? [];
        if (!empty($destination['conflict']) || (int) ($destination['id'] ?? 0) !== (int) $item['destination_id'] || ($destination['hash'] ?? '') !== $item['destination_hash']) { throw new \RuntimeException('Destination identity or data changed after planning. Replan.'); }
      }
      $transaction = $this->store->transaction();
      $this->store->baselineBatch($run, $items);
      $this->store->batchStatus($run['run_id'], $ids, 'applied');
      unset($transaction);
    }
    catch (\Throwable $e) {
      if (isset($transaction)) { $transaction->rollBack(); }
      $this->store->itemStatus($currentItem, 'error', ['reason' => 'baseline_failed', 'error_class' => get_class($e)]);
      $this->store->updateRun($run['run_id'], ['phase' => 'review']);
      throw new \RuntimeException('Baseline batch not committed. ' . $e->getMessage(), 0, $e);
    }
  }

  private function applyItem(array $run, array $item, array $options): void {
    if (method_exists($this->adapter, 'resetRuntime')) { $this->adapter->resetRuntime(); }
    $type = $run['source_type'];
    $id = (int) $item['source_id'];
    $op = $item['operation'];
    if (in_array($op, ['conflict', 'unsupported', 'local_change'], TRUE)) {
      // Source-wins never bypasses identity conflicts or unsupported data.
      $this->store->itemStatus($item, $op === 'local_change' ? 'preserved' : 'blocked');
      return;
    }
    if ($op === 'update_candidate' && !$options['source_wins']) {
      $this->store->itemStatus($item, 'blocked'); return;
    }
    if ($op === 'deleted' && $options['missing_source'] === 'report') {
      $this->store->itemStatus($item, 'reported'); return;
    }
    if ($op === 'deleted' && !empty(json_decode($item['details'], TRUE)['requires_source_wins']) && !$options['source_wins']) {
      $this->store->itemStatus($item, 'blocked'); return;
    }
    $fresh = $this->source->fingerprints($type, [$id]);
    if ($op === 'deleted') {
      if ($this->source->existing([$id])) { throw new \RuntimeException('A reported source deletion no longer holds. Replan.'); }
    }
    elseif (!isset($fresh[$id]) || !hash_equals($item['source_hash'], $fresh[$id]['hash'])) {
      throw new \RuntimeException('Source changed after planning. Replan.');
    }
    $destination = $this->adapter->destinations($type, [$id])[$id] ?? ['id' => NULL, 'hash' => ''];
    if (!empty($destination['conflict']) || (int) ($destination['id'] ?? 0) !== (int) $item['destination_id'] || ($destination['hash'] ?? '') !== $item['destination_hash']) {
      throw new \RuntimeException('Destination identity or data changed after planning. Replan.');
    }
    if (in_array($op, ['baseline', 'unchanged'], TRUE)) {
      $this->store->baseline($run, $item, (int) $item['destination_id'], $item['destination_hash']);
      $this->store->itemStatus($item, 'applied'); return;
    }
    $operation = $op === 'deleted' ? 'unpublish' : ($op === 'update_candidate' ? 'update' : $op);
    $result = $this->adapter->apply($type, $id, $item['destination_id'] ? (int) $item['destination_id'] : NULL, $operation, [
      'source_hash' => $item['source_hash'], 'destination_hash' => $item['destination_hash'], 'mapping_hash' => $run['mapping_hash'], 'source_wins' => $options['source_wins'], 'source_languages' => json_decode($item['details'], TRUE, 512, JSON_THROW_ON_ERROR)['source_languages_before'] ?? [],
    ]);
    if (empty($result['destination_id']) || empty($result['destination_hash'])) { throw new \RuntimeException('Destination adapter did not confirm success.'); }
    if ($op !== 'deleted') {
      $fresh = $this->source->fingerprints($type, [$id]);
      if (!isset($fresh[$id]) || !hash_equals($item['source_hash'], $fresh[$id]['hash'])) { throw new \RuntimeException('Source changed during write; rolled back. Replan.'); }
      $this->store->baseline($run, $item, (int) $result['destination_id'], $result['destination_hash']);
    }
    if ($op === 'deleted') {
      if ($this->source->existing([$id])) { throw new \RuntimeException('Source reappeared during unpublish; rolled back. Replan.'); }
      // A tombstone remembers our own publication change, so reappearance can
      // be restored without confusing it with a later local edit.
      $item['source_hash'] = '';
      $item['source_languages'] = json_encode(json_decode($item['details'], TRUE)['source_languages_before'] ?? [], JSON_THROW_ON_ERROR);
      $this->store->baseline($run, $item, (int) $result['destination_id'], $result['destination_hash']);
    }
    $written = $result['written'] ?? TRUE;
    if (!is_bool($written)) { throw new \RuntimeException('Invalid actual-write evidence from adapter.'); }
    $this->store->itemStatus($item, $written ? 'applied' : 'skipped', ['written' => $written, 'effects' => $result['effects'] ?? []]);
  }

  private function clearRuntime(): void {
    if (class_exists('Drupal') && \Drupal::hasContainer()) {
      \Drupal::service('entity.memory_cache')->deleteAll();
    }
    if (method_exists($this->adapter, 'resetRuntime')) { $this->adapter->resetRuntime(); }
    if (method_exists($this->source, 'refresh')) { $this->source->refresh(); }
    gc_collect_cycles();
  }
}
