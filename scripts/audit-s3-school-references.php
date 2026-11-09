<?php

/**
 * Read-only audit: drush php:script scripts/audit-s3-school-references.php.
 *
 * Distinguishes an empty field from a reference to an absent school. Resolves
 * current V2 references without creating entities or changing ID sequences.
 */

use Drupal\Core\Database\Database;

$db = \Drupal::database();
$key = $db->getKey();
$rows = $db->query("SELECT n.nid,n.title,ed.field_edoovillage_destination_target_id AS destination FROM {node_field_data} n INNER JOIN {node} b ON b.nid=n.nid INNER JOIN {node__field_dootronic_status} s ON s.entity_id=n.nid AND s.deleted=0 LEFT JOIN {node__field_edoovillage_destination} ed ON ed.entity_id=n.nid AND ed.deleted=0 LEFT JOIN {node} school ON school.nid=ed.field_edoovillage_destination_target_id AND school.type='edoovillage' WHERE n.type='dootronic' AND n.default_langcode=1 AND n.status=1 AND s.field_dootronic_status_value='S3' AND school.nid IS NULL ORDER BY n.nid")->fetchAll(\PDO::FETCH_ASSOC);
$summary = ['missing_or_dangling' => count($rows), 'repairable_existing_school' => 0, 'missing_school_entity' => 0, 'no_valid_source_reference' => 0];
try {
  $source = \Drupal::service('labdoo_migrate.database.external_connection_manager')->setConnection();
  foreach ($rows as &$row) {
    $source_refs = $source->query("SELECT DISTINCT f.field_edoovillage_destination_target_id AS nid,n.title,n.status FROM {field_data_field_edoovillage_destination} f INNER JOIN {node} n ON n.nid=f.field_edoovillage_destination_target_id AND n.type='edoovillage' WHERE f.entity_id=:nid AND f.deleted=0", [':nid' => $row['nid']])->fetchAll(\PDO::FETCH_ASSOC);
    $row['source_schools'] = $source_refs;
    $valid = [];
    foreach ($source_refs as $ref) {
      if ($db->query("SELECT nid FROM {node} WHERE nid=:nid AND type='edoovillage'", [':nid' => $ref['nid']])->fetchField()) {
        $valid[] = (int) $ref['nid'];
      }
    }
    $row['existing_destination_candidates'] = $valid;
    $row['classification'] = count($valid) === 1 ? 'repairable_existing_school' : ($source_refs ? 'missing_school_entity' : 'no_valid_source_reference');
    $summary[$row['classification']]++;
  }
}
finally {
  Database::setActiveConnection($key);
}
unset($row);
echo json_encode(['summary' => $summary, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
