<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Recovers legacy dootrip location labels without saving content entities.
 */
final class DootripLocationTextCommands extends DrushCommands {

  /**
   * Constructs the command.
   */
  public function __construct(
    private readonly ConnectionManagerInterface $sourceConnection,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly CountryManagerInterface $countryManager,
  ) {
    parent::__construct();
  }

  /**
   * Imports V2 origin/destination labels matching the current V3 coordinates.
   *
   * @command labdoo:backfill-dootrip-location-text
   * @option apply Write derived location labels. Default is a read-only dry run.
   * @option nids Comma-separated V3 node IDs; omit to scan all dootrips.
   * @option limit Maximum V3 nodes to scan; 0 means unlimited.
   */
  public function backfill(array $options = [
    'apply' => FALSE,
    'nids' => '',
    'limit' => 0,
  ]): void {
    $selected = array_filter(explode(',', (string) $options['nids']), 'strlen');
    foreach ($selected as $id) {
      if (!ctype_digit($id) || (int) $id < 1) {
        throw new \InvalidArgumentException('nids must contain positive V3 node IDs.');
      }
    }
    $limit = (int) $options['limit'];
    if ($limit < 0) {
      throw new \InvalidArgumentException('limit must be zero or positive.');
    }
    $active = Database::getConnection()->getKey();
    try {
      $source = $this->sourceConnection->setConnection();
    }
    finally {
      Database::setActiveConnection($active);
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $labels = $this->keyValue->get('labdoo_dootrip_location_labels');
    $countries = $this->countryManager->getList();
    $stats = [
      'scanned' => 0,
      'recoverable' => 0,
      'updated' => 0,
      'unchanged' => 0,
      'missing_or_mismatched' => 0,
    ];
    $last = 0;
    while (!$limit || $stats['scanned'] < $limit) {
      $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'dootrip')
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
        $sourceId = $node->hasField('field_d7_nid') && !$node->get('field_d7_nid')->isEmpty()
          ? (int) $node->get('field_d7_nid')->value : $last;
        foreach (['field_origin_of_the_trip', 'field_destination_of_the_trip'] as $field) {
          $item = $node->get($field)->first();
          if (!$item) {
            continue;
          }
          $rows = $source->query("SELECT l.country,l.city,l.postal_code,l.street,l.additional,l.latitude,l.longitude
            FROM {node} n JOIN {field_data_" . $field . "} f ON f.entity_id=n.nid AND f.revision_id=n.vid
            JOIN {location} l ON l.lid=f." . $field . "_lid
            WHERE n.nid=:nid AND n.type='dootrip' AND f.deleted=0 AND f.entity_type='node'",
            [':nid' => $sourceId])->fetchAll();
          if (count($rows) !== 1 || !is_numeric($rows[0]->latitude) || !is_numeric($rows[0]->longitude)
            || abs((float) $rows[0]->latitude - (float) $item->lat) > 0.00001
            || abs((float) $rows[0]->longitude - (float) $item->lon) > 0.00001) {
            $stats['missing_or_mismatched']++;
            continue;
          }
          $row = $rows[0];
          $country = strtoupper(trim((string) $row->country));
          $countryName = isset($countries[$country]) ? (string) $countries[$country] : trim((string) $row->country);
          $parts = array_map('trim', [
            (string) $row->street,
            (string) $row->additional,
            (string) $row->postal_code . ' ' . (string) $row->city,
            $countryName,
          ]);
          $label = implode(', ', array_filter($parts, 'strlen'));
          if ($label === '') {
            $stats['missing_or_mismatched']++;
            continue;
          }
          $key = $last . ':' . $field;
          $replacement = ['value' => $item->value, 'label' => $label];
          if ($labels->get($key) === $replacement) {
            $stats['unchanged']++;
            continue;
          }
          $stats['recoverable']++;
          if ($options['apply']) {
            $labels->set($key, $replacement);
            Cache::invalidateTags([
              'labdoo_dootrip_location_labels:' . $last,
              'node:' . $last,
            ]);
            $stats['updated']++;
          }
        }
      }
      $storage->resetCache(array_values($ids));
    }
    $this->io()->writeln(($options['apply'] ? 'APPLY ' : 'DRY RUN ') . json_encode($stats, JSON_THROW_ON_ERROR));
  }

}
