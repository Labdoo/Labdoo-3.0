<?php

declare(strict_types=1);

namespace Drupal\labdoo_map\Service;

/**
 * Groups visible points without discarding coincident devices or their counts.
 */
final class MapPointClusterer {

  /**
   * Returns grid clusters, or individual points at street-level zoom.
   */
  public function cluster(array $points, int $zoom, ?array $viewport = NULL): array {
    $cells = [];
    $visible = 0;
    $bounds = NULL;
    $step = 360 / (2 ** ($zoom + 2));
    foreach ($points as $point) {
      $lat = $point['lat'];
      $lon = $point['lon'];
      if ($bounds === NULL) {
        $bounds = [[$lat, $lon], [$lat, $lon]];
      }
      else {
        $bounds[0][0] = min($bounds[0][0], $lat);
        $bounds[0][1] = min($bounds[0][1], $lon);
        $bounds[1][0] = max($bounds[1][0], $lat);
        $bounds[1][1] = max($bounds[1][1], $lon);
      }
      if ($viewport !== NULL) {
        [$west, $south, $east, $north] = $viewport;
        $insideLongitude = $west <= $east ? $lon >= $west && $lon <= $east : $lon >= $west || $lon <= $east;
        if ($lat < $south || $lat > $north || !$insideLongitude) {
          continue;
        }
      }
      $visible++;
      if ($zoom >= 14) {
        $cells[] = $point + ['count' => 1];
        continue;
      }
      $key = (int) floor(($lat + 90) / $step) . ':' . (int) floor(($lon + 180) / $step);
      if (!isset($cells[$key])) {
        $cells[$key] = $point + ['count' => 1];
      }
      else {
        $cells[$key]['lat'] += $lat;
        $cells[$key]['lon'] += $lon;
        $cells[$key]['count']++;
      }
    }
    foreach ($cells as &$cell) {
      if ($cell['count'] > 1) {
        $cell['lat'] = round($cell['lat'] / $cell['count'], 6);
        $cell['lon'] = round($cell['lon'] / $cell['count'], 6);
        unset($cell['id'], $cell['title']);
      }
    }
    return [
      'points' => array_values($cells),
      'total' => count($points),
      'visible' => $visible,
      'bounds' => $bounds,
      'zoom' => $zoom,
    ];
  }

}
