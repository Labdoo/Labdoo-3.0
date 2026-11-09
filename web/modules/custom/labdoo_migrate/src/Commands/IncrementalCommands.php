<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drupal\labdoo_migrate\Services\Incremental\DeltaEngine;
use Drupal\labdoo_migrate\Services\Incremental\DeltaStore;
use Drupal\labdoo_migrate\Services\Incremental\NodeSourceReader;
use Drupal\labdoo_migrate\Services\Incremental\NodeSyncAdapter;
use Drush\Commands\DrushCommands;

/** Explicit, resumable node deltas; never enabled as an automatic cron job. */
final class IncrementalCommands extends DrushCommands {
  private ?DeltaEngine $engine = NULL;

  public function __construct(private Connection $database, private ConnectionManagerInterface $sourceManager, private LockBackendInterface $lock) { parent::__construct(); }

  private function engine(): DeltaEngine {
    if ($this->engine) { return $this->engine; }
    $active = Database::getConnection()->getKey();
    try { $source = $this->sourceManager->setConnection(); }
    finally { Database::setActiveConnection($active); }
    $options = $source->getConnectionOptions();
    $identity = hash('sha256', json_encode(array_intersect_key($options, array_fill_keys(['driver', 'database', 'prefix', 'host', 'port', 'username'], TRUE)), JSON_THROW_ON_ERROR));
    $reader = new NodeSourceReader($source);
    return $this->engine = new DeltaEngine($reader, new NodeSyncAdapter($source, $this->database, $reader), new DeltaStore($this->database), $this->lock, $identity);
  }

  private function options(array $options): array {
    foreach (['batch-size', 'max-items'] as $key) {
      if (!preg_match('/^[0-9]+$/D', (string) $options[$key])) { throw new \InvalidArgumentException($key . ' must be a non-negative integer.'); }
    }
    return ['batch_size' => (int) $options['batch-size'], 'max_items' => (int) $options['max-items'], 'run_id' => $options['run'] ?? NULL];
  }

  private function planTypes(string $type, array $options): array {
    if ($type !== 'all') { return $this->engine()->plan($type, $options); }
    if (!empty($options['run_id'])) { throw new \InvalidArgumentException('Resume one manifest using its original source type, not all.'); }
    $runs = [];
    foreach (glob(dirname(__DIR__, 2) . '/config/fields_mapping/*.json') as $file) {
      $config = json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
      if (($config['entity_type'] ?? '') === 'node' && isset($config['source_type'])) { $runs[] = $this->engine()->plan($config['source_type'], $options); }
    }
    return ['scope' => 'all_configured_node_types', 'max_items_is_per_type' => TRUE, 'runs' => $runs];
  }

  private function emit(array $report): void { $this->output()->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }

  /**
   * Preview node deltas without writing manifests, baselines or content.
   *
   * @command labdoo-delta:preview
   * @param string $type D7 node type, or all configured node types.
   * @option batch-size Maximum roots per SQL page (1..500).
   * @option max-items Maximum inventory rows this call, per type; 0 scans all.
   * @usage labdoo-delta:preview laptop --max-items=500
   */
  public function preview(string $type = 'all', array $options = ['batch-size' => 100, 'max-items' => 0]): void {
    $this->emit($this->planTypes($type, $this->options($options) + ['dry_run' => TRUE]));
  }

  /**
   * Store or resume a node delta manifest; writes only migration metadata.
   *
   * @command labdoo-delta:plan
   * @param string $type D7 node type, or all for new manifests.
   * @option batch-size Maximum roots per SQL page (1..500).
   * @option max-items Maximum inventory rows this call, per type; 0 scans all.
   * @option run Existing manifest ID to resume, preserving its original ceiling.
   * @usage labdoo-delta:plan laptop --max-items=1000
   */
  public function plan(string $type = 'all', array $options = ['batch-size' => 100, 'max-items' => 0, 'run' => NULL]): void {
    $this->emit($this->planTypes($type, $this->options($options)));
  }

  /**
   * Apply a completed manifest; defaults preserve conflicts and source deletions.
   *
   * @command labdoo-delta:apply
   * @param string $run Manifest ID returned by labdoo-delta:plan.
   * @option batch-size Maximum work rows per SQL page (1..500).
   * @option max-items Maximum operations this call; repeat to resume. 0 is unlimited.
   * @option source-wins Explicitly adopt first-run differences; never bypass identity or two-sided conflicts.
   * @option missing-source report (default) or explicitly unpublish; never hard-deletes.
   * @option confirm-mass-unpublish Explicitly allow verified mass deletions or an empty source.
   * @option retry-errors Retry failed items after correcting their cause; stale plans still fail.
   * @usage labdoo-delta:apply MANIFEST_ID --max-items=100
   */
  public function apply(string $run, array $options = ['batch-size' => 100, 'max-items' => 500, 'source-wins' => FALSE, 'missing-source' => 'report', 'retry-errors' => FALSE, 'confirm-mass-unpublish' => FALSE]): void {
    $settings = $this->options($options) + ['source_wins' => (bool) $options['source-wins'], 'missing_source' => $options['missing-source'], 'retry_errors' => (bool) $options['retry-errors'], 'confirm_mass_unpublish' => (bool) $options['confirm-mass-unpublish']];
    $this->emit($this->engine()->apply($run, $settings));
  }

  /**
   * List source IDs and reasons from a manifest in bounded keyset pages.
   *
   * @command labdoo-delta:items
   * @param string $run Manifest ID.
   * @option operation Filter create/update/update_candidate/conflict/deleted/etc.
   * @option status Filter pending/applied/skipped/blocked/error/etc.
   * @option after Last source ID of the previous page.
   * @option limit Maximum rows (1..500).
   */
  public function items(string $run, array $options = ['operation' => NULL, 'status' => NULL, 'after' => 0, 'limit' => 100]): void {
    foreach (['after', 'limit'] as $key) {
      if (!preg_match('/^[0-9]+$/D', (string) $options[$key])) { throw new \InvalidArgumentException('after/limit must be non-negative integers.'); }
    }
    $this->emit(['run_id' => $run, 'items' => (new DeltaStore($this->database))->listItems($run, (int) $options['after'], (int) $options['limit'], $options['operation'], $options['status'])]);
  }

  /**
   * Optionally enqueue existing derived workers for actually changed targets.
   *
   * This schedules work only; it does not verify or execute derived calculations.
   * Review the reported limitations before using this at cutover.
   *
   * @command labdoo-delta:enqueue-derived
   * @param string $run Applied manifest ID.
   * @option limit Maximum applied rows per call (1..500).
   */
  public function enqueueDerived(string $run, array $options = ['limit' => 100]): void {
    if (!preg_match('/^[0-9]+$/D', (string) $options['limit'])) { throw new \InvalidArgumentException('limit must be an integer.'); }
    $this->emit((new \Drupal\labdoo_migrate\Services\Incremental\DeltaFinalizer($this->database))->finalize($run, (int) $options['limit']));
  }

  /**
   * Read a manifest's progress and operation counts.
   *
   * @command labdoo-delta:status
   * @param string $run Existing manifest ID.
   */
  public function status(string $run): void { $this->emit((new DeltaStore($this->database))->summary($run)); }
}
