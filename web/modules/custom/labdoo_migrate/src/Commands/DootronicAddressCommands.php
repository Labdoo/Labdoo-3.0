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
        // An interrupted previous run may have committed a field batch before
        // invalidating entity cache. Always compare fresh destination values.
        $storage->resetCache(array_values($ids));
        $nodes = $storage->loadMultiple($ids);
        $tags = [];
        $changed = [];
        // Commit and invalidate cache once per bounded batch, rather than
        // forcing a database commit for every one of the 63,000 addresses.
        $transaction = $apply ? Database::getConnection()->startTransaction() : NULL;
        try {
          foreach ($nodes as $node) {
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
            $node->set('field_address', $address);
            $node->setNewRevision(FALSE);
            $node->isDefaultRevision(TRUE);
            $changed[] = $node;
            $tags = array_merge($tags, $node->getCacheTagsToInvalidate());
            $stats['updated']++;
          }
          if ($changed) {
            $this->writeAddresses($changed);
          }
          unset($transaction);
        }
        catch (\Throwable $e) {
          $transaction?->rollBack();
          throw $e;
        }
        $storage->resetCache(array_values($ids));
        if ($tags) {
          Cache::invalidateTags(array_values(array_unique($tags)));
        }
        if ($stats['scanned'] % 1000 === 0) {
          $this->io()->writeln('Scanned: ' . $stats['scanned'] . '; updated: ' . $stats['updated']);
        }
      }
    }
    finally {
      if (is_resource($backup)) {
        fclose($backup);
      }
    }
    $this->io()->writeln(($apply ? 'APPLY ' : 'DRY RUN ') . json_encode($stats, JSON_THROW_ON_ERROR));
  }

  /**
   * Writes only address data, using Drupal's dedicated field table mapping.
   *
   * Batching the same rows as SqlContentEntityStorage avoids four database
   * round trips per node. Other translations and old revisions are preserved.
   * The caller holds the transaction and has already backed up every change.
   *
   * @param \Drupal\node\NodeInterface[] $nodes
   *   Existing nodes with recovered addresses in their default translation.
   */
  private function writeAddresses(array $nodes): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $mapping = $storage->getTableMapping();
    $definition = reset($nodes)->getFieldDefinition('field_address')->getFieldStorageDefinition();
    $dataTable = $mapping->getDedicatedDataTableName($definition);
    $revisionTable = $mapping->getDedicatedRevisionTableName($definition);
    $columns = ['entity_id', 'revision_id', 'bundle', 'delta', 'langcode'];
    foreach ($definition->getColumns() as $property => $settings) {
      $columns[] = $mapping->getFieldColumnName($definition, $property);
    }
    $db = Database::getConnection();
    $currentRevisions = $db->condition('OR');
    $ids = [];
    $rows = [];
    foreach ($nodes as $node) {
      $ids[] = $node->id();
      $currentRevisions->condition($db->condition('AND')
        ->condition('entity_id', $node->id())
        ->condition('revision_id', $node->getRevisionId()));
      $languages = $node->getFieldDefinition('field_address')->isTranslatable()
        ? $node->getTranslationLanguages() : [$node->language()->getId() => $node->language()];
      foreach ($languages as $langcode => $language) {
        $items = $node->getTranslation($langcode)->get('field_address');
        $items->filterEmptyItems();
        foreach ($items as $delta => $item) {
          $row = [
            $node->id(),
            $node->getRevisionId(),
            $node->bundle(),
            $delta,
            $langcode,
          ];
          $value = $item->getValue();
          foreach ($definition->getColumns() as $property => $settings) {
            $row[] = $value[$property] ?? NULL;
          }
          $rows[] = $row;
        }
      }
    }
    // Stop safely if a user saved one of these nodes after it was loaded.
    $current = $db->select('node', 'n')->fields('n', ['nid', 'vid'])
      ->condition('nid', $ids, 'IN')->forUpdate()->execute()->fetchAllKeyed();
    $defaultAddresses = $db->condition('OR');
    foreach ($nodes as $node) {
      if (($current[$node->id()] ?? NULL) != $node->getRevisionId()) {
        throw new \RuntimeException('A node revision changed during recovery; rerun the command.');
      }
      $defaultAddresses->condition($db->condition('AND')
        ->condition('entity_id', $node->id())
        ->condition('langcode', $node->language()->getId()));
    }
    $countryColumn = $mapping->getFieldColumnName($definition, 'country_code');
    $newAddresses = $db->select($dataTable, 'a')->fields('a', ['entity_id'])
      ->condition($defaultAddresses)->isNotNull($countryColumn)
      ->condition($countryColumn, '', '<>')->range(0, 1)->execute()->fetchField();
    if ($newAddresses !== FALSE) {
      throw new \RuntimeException('An address was populated during recovery; rerun the command.');
    }
    $db->delete($dataTable)->condition('entity_id', $ids, 'IN')->execute();
    $db->delete($revisionTable)->condition($currentRevisions)->execute();
    foreach ([$dataTable, $revisionTable] as $table) {
      $insert = $db->insert($table)->fields($columns);
      foreach ($rows as $row) {
        $insert->values($row);
      }
      $insert->execute();
    }
  }

}
