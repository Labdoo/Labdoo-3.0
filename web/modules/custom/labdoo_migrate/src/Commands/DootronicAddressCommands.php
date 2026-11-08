<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Locale\CountryManager;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Recovers missing addresses without saving nodes or allocating revisions.
 */
final class DootronicAddressCommands extends DrushCommands {

  /**
   * Constructs the command.
   */
  public function __construct(
    private readonly ConnectionManagerInterface $sourceConnection,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Recovers empty dootronic addresses from the current V2 revision only.
   *
   * @command labdoo:backfill-dootronic-addresses
   * @option apply Write changes. The default is a read-only dry run.
   * @option nids Comma-separated V3 node IDs; omit to scan all dootronics.
   * @option limit Maximum number of V3 nodes to scan; 0 means unlimited.
   * @option backup-path Private JSONL backup path, required with --apply.
   * @usage labdoo:backfill-dootronic-addresses --nids=1829
   *   Preview the address recovery for one existing node.
   */
  public function backfill(array $options = [
    'apply' => FALSE,
    'nids' => '',
    'limit' => 0,
    'backup-path' => '',
  ]): void {
    $apply = (bool) $options['apply'];
    $backup = NULL;
    if ($apply) {
      $path = (string) $options['backup-path'];
      if ($path === '' || file_exists($path)) {
        throw new \InvalidArgumentException('Use a new, private --backup-path with --apply.');
      }
      $mask = umask(0077);
      try {
        $backup = fopen($path, 'x');
      }
      finally {
        umask($mask);
      }
      if (!$backup) {
        throw new \RuntimeException('Cannot open address backup.');
      }
    }
    $active = Database::getConnection()->getKey();
    try {
      $source = $this->sourceConnection->setConnection();
    }
    finally {
      Database::setActiveConnection($active);
    }
    $storage = $this->entityTypeManager->getStorage('node');
    // Limit the write to the address tables of the existing default revision.
    // This follows the existing coordinate repair: no entity save hooks run.
    $writeField = new \ReflectionMethod($storage, 'saveToDedicatedTables');
    $writeField->setAccessible(TRUE);
    $selected = array_filter(explode(',', (string) $options['nids']), 'strlen');
    foreach ($selected as $id) {
      if (!ctype_digit($id) || (int) $id < 1) {
        throw new \InvalidArgumentException('nids must contain positive V3 node IDs.');
      }
    }
    $stats = [
      'scanned' => 0,
      'recoverable' => 0,
      'updated' => 0,
      'populated' => 0,
      'missing_or_ambiguous_source' => 0,
    ];
    $last = 0;
    $limit = (int) $options['limit'];
    if ($limit < 0) {
      throw new \InvalidArgumentException('limit must be zero or positive.');
    }
    try {
      while (!$limit || $stats['scanned'] < $limit) {
        $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'dootronic')
          ->condition('nid', $last, '>')->sort('nid')->range(0, $limit ? min(100, $limit - $stats['scanned']) : 100);
        if ($selected) {
          $query->condition('nid', array_map('intval', $selected), 'IN');
        }
        $ids = $query->execute();
        if (!$ids) {
          break;
        }
        foreach ($storage->loadMultiple($ids) as $node) {
          $last = (int) $node->id();
          $stats['scanned']++;
          if (!$node->get('field_address')->isEmpty()) {
            $stats['populated']++;
            continue;
          }
          $sourceId = $node->hasField('field_d7_nid') && !$node->get('field_d7_nid')->isEmpty()
            ? (int) $node->get('field_d7_nid')->value : $last;
          $rows = $source->query("SELECT l.country,l.city,l.postal_code,l.street
            FROM {node} n JOIN {location_instance} li ON li.nid=n.nid AND li.vid=n.vid
            JOIN {location} l ON l.lid=li.lid WHERE n.nid=:nid AND n.type='laptop'",
            [':nid' => $sourceId])->fetchAll();
          if (count($rows) !== 1 || !isset(CountryManager::getStandardList()[strtoupper(trim((string) $rows[0]->country))])) {
            $stats['missing_or_ambiguous_source']++;
            continue;
          }
          $row = $rows[0];
          $address = ['country_code' => strtoupper(trim($row->country))];
          $components = [
            'city' => 'locality',
            'postal_code' => 'postal_code',
            'street' => 'address_line1',
          ];
          foreach ($components as $from => $to) {
            $address[$to] = trim((string) $row->$from);
          }
          $stats['recoverable']++;
          if (!$apply) {
            continue;
          }
          $record = [
            'nid' => $last,
            'vid' => (int) $node->getRevisionId(),
            'source_nid' => $sourceId,
            'langcode' => $node->language()->getId(),
            'before' => $node->get('field_address')->getValue(),
            'after' => $address,
          ];
          $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
          if (fwrite($backup, $line) !== strlen($line) || !fflush($backup)) {
            throw new \RuntimeException('Cannot persist address backup; stopping.');
          }
          $transaction = Database::getConnection()->startTransaction();
          try {
            $node->set('field_address', $address);
            $node->setNewRevision(FALSE);
            $node->isDefaultRevision(TRUE);
            $writeField->invoke($storage, $node, TRUE, ['field_address']);
          }
          catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
          }
          unset($transaction);
          $storage->resetCache([$last]);
          Cache::invalidateTags($node->getCacheTagsToInvalidate());
          $stats['updated']++;
        }
        $storage->resetCache(array_values($ids));
      }
    }
    finally {
      if (is_resource($backup)) {
        fclose($backup);
      }
    }
    $this->io()->writeln(($apply ? 'APPLY ' : 'DRY RUN ') . json_encode($stats, JSON_THROW_ON_ERROR));
  }

}
