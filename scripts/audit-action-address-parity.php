<?php

/**
 * Read-only recovery plan for historical action city/country from V2.
 *
 * Matches coordinates to unambiguous stored V2 location labels. Does not infer
 * an action's historical location from the current location of its laptop.
 */

use Drupal\Core\Database\Database;

$db = \Drupal::database();
$key = $db->getKey();
$rows = $db->query("SELECT n.nid,n.created,n.vid,n.langcode,a.field_address_locality AS city,a.field_address_country_code AS country,l.field_locations_lat AS lat,l.field_locations_lon AS lon FROM {node_field_data} n INNER JOIN {node} b ON b.nid=n.nid LEFT JOIN {node__field_address} a ON a.entity_id=n.nid AND a.deleted=0 AND a.delta=0 AND a.langcode=n.langcode LEFT JOIN {node__field_locations} l ON l.entity_id=n.nid AND l.deleted=0 AND l.delta=0 AND l.langcode=n.langcode WHERE n.type='action' AND n.default_langcode=1 AND n.status=1 AND (a.field_address_locality IS NULL OR TRIM(a.field_address_locality)='' OR a.field_address_country_code IS NULL OR CHAR_LENGTH(a.field_address_country_code)<>2) ORDER BY n.nid")->fetchAllAssoc('nid');
$report = ['incomplete_published_actions' => count($rows), 'eligible' => 0, 'identity_or_location_mismatch' => 0, 'source_without_labels' => 0, 'recovery_candidates' => []];
$countries = array_keys(\Drupal::service('country_manager')->getList());
try {
  $source = \Drupal::service('labdoo_migrate.database.external_connection_manager')->setConnection();
  // Actions were regenerated in V3 and do not retain V2 action IDs. Match
  // historical coordinates to V2's stored labels, never to a moved laptop.
  // Five decimals is roughly one metre; conflicting labels are skipped.
  $labels = [];
  $source_rows = $source->query("SELECT latitude,longitude,city,country FROM {location} WHERE latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180 AND NOT(latitude=0 AND longitude=0) AND NOT(latitude=-90 AND longitude=-180) AND (city<>'' OR country<>'')");
  foreach ($source_rows as $s) {
    $country = strtoupper(trim((string) $s->country));
    if (!in_array($country, $countries, TRUE)) {
      continue;
    }
    $coordinate = sprintf('%.5F,%.5F', $s->latitude, $s->longitude);
    $city = trim((string) $s->city);
    $labels[$coordinate][$country][mb_strtolower($city)] = $city;
  }
  foreach ($rows as $nid => $r) {
    if (!is_numeric($r->lat) || !is_numeric($r->lon) || abs((float) $r->lat) > 90 || abs((float) $r->lon) > 180 || ((float) $r->lat === 0.0 && (float) $r->lon === 0.0) || ((float) $r->lat === -90.0 && (float) $r->lon === -180.0)) {
      $report['identity_or_location_mismatch']++;
      continue;
    }
    $coordinate = sprintf('%.5F,%.5F', $r->lat, $r->lon);
    $matches = $labels[$coordinate] ?? [];
    $known_country = strtoupper(trim((string) $r->country));
    if (in_array($known_country, $countries, TRUE)) {
      $matches = isset($matches[$known_country]) ? [$known_country => $matches[$known_country]] : [];
    }
    if (count($matches) !== 1) {
      $report['identity_or_location_mismatch']++;
      continue;
    }
    $country = array_key_first($matches);
    $cities = array_filter($matches[$country], static fn($value) => $value !== '');
    $after = ['city' => trim((string) $r->city), 'country' => trim((string) $r->country)];
    $changed = FALSE;
    if ($after['city'] === '' && count($cities) === 1) {
      $after['city'] = reset($cities);
      $changed = TRUE;
    }
    if (!in_array($known_country, $countries, TRUE)) {
      $after['country'] = $country;
      $changed = TRUE;
    }
    if (!$changed) {
      $report['source_without_labels']++;
      continue;
    }
    $report['eligible']++;
    $report['recovery_candidates'][$nid] = ['vid' => $r->vid, 'langcode' => $r->langcode, 'before_city' => $r->city, 'before_country' => $r->country, 'coordinate' => $coordinate, 'after' => $after];
  }
}
finally {
  Database::setActiveConnection($key);
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
