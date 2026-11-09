<?php

/**
 * Read-only audit of published V3 locations absent despite valid V2 locations.
 *
 * No entity saves, external geocoding, or new identifiers. Source selection uses
 * current node revisions. Invalid sentinels include both 0,0 and -90,-180.
 */

use Drupal\Core\Database\Database;

function labdooAuditValidLocation($lat, $lon): bool {
  return is_numeric($lat) && is_numeric($lon)
    && abs((float) $lat) <= 90 && abs((float) $lon) <= 180
    && !((float) $lat === 0.0 && (float) $lon === 0.0)
    && !((float) $lat === -90.0 && (float) $lon === -180.0);
}

$db = \Drupal::database();
$key = $db->getKey();
$out = [];
try {
  $source = \Drupal::service('labdoo_migrate.database.external_connection_manager')->setConnection();
  foreach (['dootronic' => ['field_locations'], 'edoovillage' => ['field_location'], 'hub' => ['field_locations'], 'dootrip' => ['field_origin_of_the_trip', 'field_destination_of_the_trip']] as $bundle => $fields) {
    foreach ($fields as $field) {
      $all = $db->query("SELECT n.nid,l.{$field}_lat AS lat,l.{$field}_lon AS lon FROM {node_field_data} n INNER JOIN {node} b ON b.nid=n.nid LEFT JOIN {node__{$field}} l ON l.entity_id=n.nid AND l.deleted=0 WHERE n.type=:bundle AND n.default_langcode=1 AND n.status=1", [':bundle' => $bundle])->fetchAll();
      $valid = $nodes = [];
      foreach ($all as $row) {
        $nodes[(int) $row->nid] = TRUE;
        if (labdooAuditValidLocation($row->lat, $row->lon)) {
          $valid[(int) $row->nid] = TRUE;
        }
      }
      $missing = array_diff_key($nodes, $valid);
      $source_valid = [];
      $source_present = [];
      foreach (array_chunk(array_keys($missing), 500) as $ids) {
        if ($bundle === 'dootrip') {
          $q = $source->select('field_data_' . $field, 'f');
          $q->innerJoin('location', 'l', "l.lid=f.{$field}_lid");
          $q->addField('f', 'entity_id', 'nid');
          $q->condition('f.deleted', 0)->condition('f.entity_id', $ids, 'IN');
        }
        else {
          $q = $source->select('node', 'n');
          $q->innerJoin('location_instance', 'li', 'li.nid=n.nid AND li.vid=n.vid');
          $q->innerJoin('location', 'l', 'l.lid=li.lid');
          $q->addField('n', 'nid');
          $q->condition('n.nid', $ids, 'IN');
        }
        $q->addField('l', 'latitude', 'lat');
        $q->addField('l', 'longitude', 'lon');
        foreach ($q->execute() as $row) {
          $source_present[(int) $row->nid] = TRUE;
          if (labdooAuditValidLocation($row->lat, $row->lon)) {
            $source_valid[(int) $row->nid][] = [(float) $row->lat, (float) $row->lon];
          }
        }
      }
      $out[$bundle . ':' . $field] = ['published_real_nodes' => count($nodes), 'valid_v3' => count($valid), 'invalid_or_missing_v3' => count($missing), 'recoverable_from_current_v2' => count($source_valid), 'no_valid_current_v2_location' => count($missing) - count($source_valid), 'recovery_candidates' => $source_valid];
    }
  }
}
finally {
  Database::setActiveConnection($key);
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
