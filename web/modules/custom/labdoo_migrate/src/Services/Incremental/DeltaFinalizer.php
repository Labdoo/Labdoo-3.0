<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\node\NodeInterface;

/** Enqueues bounded, checkpointed recomputations; never saves content itself. */
final class DeltaFinalizer {

  private const LOCK = 'labdoo_migrate.delta';
  private const OPERATIONS = ['create', 'update', 'update_candidate', 'deleted'];
  private const QUEUES = [
    'dootronic' => ['labdoo_dootronics_recompute', 'labdoo_dootronics.compute', 'enqueueRecompute'],
    'dootrip' => ['labdoo_dootrip_calculate_capacity', 'labdoo_dootrip.compute', 'enqueueCapacityRecompute'],
    'edoovillage' => ['labdoo_edoovillage_recompute', 'labdoo_edoovillage.compute', 'enqueueRecompute'],
    'hub' => ['labdoo_hub_recompute', 'labdoo_hub.compute', 'enqueueRecompute'],
  ];

  public function __construct(private Connection $database) {}

  private function pending(string $runId, bool $count = FALSE) {
    $query = $this->database->select(DeltaStore::ITEM, 'i')->condition('run_id', $runId)->condition('status', 'applied')->condition('operation', self::OPERATIONS, 'IN');
    $query->where("NOT (COALESCE(JSON_TYPE(JSON_EXTRACT(i.details, '$.derived_queued')), 'NULL') = 'BOOLEAN' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(i.details, '$.derived_queued')), 'false') = 'true')");
    return $count ? $query->countQuery() : $query->fields('i')->orderBy('source_id');
  }

  /** All queues and checkpoints must participate in the same local transaction. */
  private function assertQueue(string $bundle): void {
    $queue = \Drupal::queue(self::QUEUES[$bundle][0]);
    if (!$queue instanceof DatabaseQueue) {
      throw new \RuntimeException('Delta finalization requires transactional database recompute queues.');
    }
    $property = new \ReflectionProperty(DatabaseQueue::class, 'connection');
    if ($property->getValue($queue) !== $this->database) {
      throw new \RuntimeException('Queue and manifest must use the same database connection.');
    }
  }

  private function add(array &$targets, string $bundle, $id): void {
    if (!isset(self::QUEUES[$bundle])) { return; }
    if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id < 1) {
      throw new \RuntimeException('Invalid derived target identity in manifest.');
    }
    $targets[$bundle][(int) $id] = (int) $id;
  }

  /** Includes old/new evidence and currently connected node references only. */
  private function targets(NodeInterface $node, array $details): array {
    $targets = [];
    $this->add($targets, $node->bundle(), (int) $node->id());
    foreach (['before', 'after'] as $side) {
      foreach ($details['effects'][$side] ?? [] as $bundle => $ids) {
        if (!is_array($ids)) { throw new \RuntimeException('Malformed derived effects.'); }
        foreach ($ids as $id) { $this->add($targets, $bundle, $id); }
      }
    }
    $references = [];
    foreach ($node->getTranslationLanguages() as $lang => $language) {
      foreach ($node->getTranslation($lang)->getFields(FALSE) as $field) {
        $definition = $field->getFieldDefinition();
        if ($definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'node') {
          foreach ($field as $item) {
            if ($item->target_id) { $references[(int) $item->target_id] = (int) $item->target_id; }
          }
        }
      }
    }
    if (count($references) > 1000) { throw new \RuntimeException('Manifest item exceeds 1000 related node targets.'); }
    foreach (ReadOnlyEntityLoader::storage('node')->loadMultiple(array_values($references)) as $related) {
      $this->add($targets, $related->bundle(), (int) $related->id());
    }
    if (array_sum(array_map('count', $targets)) > 1000) { throw new \RuntimeException('Manifest item exceeds 1000 derived targets.'); }
    return $targets;
  }

  public function finalize(string $runId, int $limit = 100): array {
    if ($limit < 1 || $limit > 500) { throw new \InvalidArgumentException('Finalization limit must be 1..500 items.'); }
    if ($this->database !== \Drupal::database() || !$this->database->schema()->tableExists('queue')) {
      throw new \RuntimeException('Finalization requires the installed local database queue.');
    }
    $store = new DeltaStore($this->database);
    $store->assertInstalled();
    $run = $store->run($runId);
    if (!in_array($run['phase'], ['applying', 'review', 'complete'], TRUE)) {
      throw new \RuntimeException('Finalize only after reviewed manifest operations have been applied.');
    }
    $lock = \Drupal::lock();
    if (!$lock->acquire(self::LOCK, 300)) {
      throw new \RuntimeException('Another delta operation is active; finalization cannot run with apply.');
    }
    $report = ['run_id' => $runId, 'processed' => 0, 'queued_targets' => [], 'not_written' => 0, 'related_evidence_missing' => 0];
    try {
      $items = $this->pending($runId)->range(0, $limit)->execute()->fetchAll(\PDO::FETCH_ASSOC);
      foreach ($items as $item) {
        if (!$lock->acquire(self::LOCK, 300)) { throw new \RuntimeException('Finalization lock lease lost.'); }
        $transaction = $this->database->startTransaction();
        try {
          $query = $this->database->select(DeltaStore::ITEM, 'i')->fields('i')->condition('run_id', $runId)->condition('source_id', $item['source_id'])->forUpdate();
          $current = $query->execute()->fetchAssoc();
          if (!$current || $current['status'] !== 'applied' || !in_array($current['operation'], self::OPERATIONS, TRUE)) {
            throw new \RuntimeException('Manifest operation changed during finalization.');
          }
          $details = json_decode($current['details'], TRUE, 512, JSON_THROW_ON_ERROR);
          if (($details['derived_queued'] ?? FALSE) === TRUE) { unset($transaction); continue; }
          if (isset($details['derived_queued']) && $details['derived_queued'] !== FALSE) {
            throw new \RuntimeException('Malformed derived checkpoint flag.');
          }
          if (!array_key_exists('written', $details)) {
            throw new \RuntimeException('Manifest lacks actual-write evidence; do not infer it from applied status.');
          }
          if ($details['written'] === TRUE) {
            $node = ReadOnlyEntityLoader::storage('node')->load((int) $current['destination_id']);
            if (!$node || $node->bundle() !== $run['bundle']) {
              throw new \RuntimeException('Finalization destination is missing or changed bundle.');
            }
            $targets = $this->targets($node, $details);
            $nodes = [];
            foreach ($targets as $bundle => $ids) {
              $this->assertQueue($bundle);
              foreach (ReadOnlyEntityLoader::storage('node')->loadMultiple(array_values($ids)) as $id => $target) {
                if ($target->bundle() !== $bundle) { throw new \RuntimeException('Derived target bundle changed.'); }
                $nodes[$bundle][$id] = $target;
              }
              if (count($nodes[$bundle] ?? []) !== count($ids)) { throw new \RuntimeException('A derived target no longer exists.'); }
            }
            foreach ($nodes as $bundle => $byId) {
              foreach ($byId as $id => $target) {
                if (in_array($bundle, ['hub', 'edoovillage'], TRUE)) {
                  $derived = (new DerivedFields($this->database))->recompute($target);
                  if ($derived['changed']) { $details['derived_updates'][(string) $id] = $derived; }
                }
                // Feeders use original_* IDs to clean inverse trip assignments.
                if ($bundle === 'dootronic' && $target->hasField('field_dootrips') && isset($details['effects']['before']['dootrip'])) {
                  $target = clone $target;
                  $original = clone $target;
                  $original->set('field_dootrips', array_map(static fn($old) => ['target_id' => (int) $old], $details['effects']['before']['dootrip']));
                  $target->original = $original;
                }
                if ($bundle === 'dootrip' && $target->hasField('field_laptops') && isset($details['effects']['before']['dootronic'])) {
                  $target = clone $target;
                  $original = clone $target;
                  $original->set('field_laptops', array_map(static fn($old) => ['target_id' => (int) $old], $details['effects']['before']['dootronic']));
                  $target->original = $original;
                }
                [, $service, $method] = self::QUEUES[$bundle];
                \Drupal::service($service)->$method($target);
                $report['queued_targets'][$bundle] = ($report['queued_targets'][$bundle] ?? 0) + 1;
              }
            }
            if (!isset($details['effects']['before'], $details['effects']['after'])) { $report['related_evidence_missing']++; }
            $details['derived_targets'] = array_map('array_values', $targets);
          }
          elseif ($details['written'] === FALSE) { $report['not_written']++; }
          else { throw new \RuntimeException('Manifest written evidence must be boolean.'); }
          $details['derived_queued'] = TRUE;
          $details['derived_queued_at'] = time();
          $this->database->update(DeltaStore::ITEM)->fields(['details' => json_encode($details, JSON_THROW_ON_ERROR), 'updated' => time()])->condition('run_id', $runId)->condition('source_id', $item['source_id'])->condition('status', 'applied')->execute();
          unset($transaction);
          $report['processed']++;
        }
        catch (\Throwable $e) { $transaction->rollBack(); throw $e; }
      }
      $report['remaining'] = (int) $this->pending($runId, TRUE)->execute()->fetchField();
      $report['limitations'] = ['Other recompute queues have been scheduled, not executed.', 'Unpublish preserves the existing repository publication-status counting semantics.'];
      return $report;
    }
    finally { $lock->release(self::LOCK); }
  }
}
