<?php

namespace Drupal\labdoo_map\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides map data for dashboards.
 */
class MapDataController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new MapDataController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Helper to check if a location is suspicious.
   *
   * @param float $lat
   *   The latitude.
   * @param float $lon
   *   The longitude.
   *
   * @return bool
   *   TRUE if the location is suspicious, FALSE otherwise.
   */
  public static function isSuspiciousLocation($lat, $lon) {
    // 0,0 is usually a sign of missing geocoding.
    if (abs($lat) < 0.0001 && abs($lon) < 0.0001) {
      return TRUE;
    }
    // Latitudes beyond the habitable range are suspicious.
    // South of -56.7 and North of 77.75 are considered polar/uninhabitable.
    if ($lat < -56.7 || $lat > 77.75) {
      return TRUE;
    }
    // Specific coordinates that are known to be "defaults" or incorrect.
    // E.g. Madrid or Barcelona city centers when only country/region was provided.
    $defaults = [
      '40.416775,-3.703790', // Madrid
      '41.385064,2.173404',  // Barcelona
    ];
    if (in_array(sprintf('%.6f,%.6f', $lat, $lon), $defaults)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Returns points for a specific content type.
   */
  public function getMapPoints($type) {
    $points = [];
    
    // Map types to their respective location fields.
    $field_map = [
      'dootronic' => 'field_location',
      'edoovillage' => 'field_location',
      'hub' => 'field_locations',
      'dootrip' => 'field_locations',
    ];

    if (!isset($field_map[$type])) {
      return new JsonResponse($points);
    }

    $field_name = $field_map[$type];
    $table_name = 'node__' . $field_name;
    $lat_col = $field_name . '_lat';
    $lon_col = $field_name . '_lon';

    $query = $this->database->select('node_field_data', 'n');
    $query->join($table_name, 'l', 'n.nid = l.entity_id');
    $query->fields('n', ['nid', 'title']);
    $query->fields('l', [$lat_col, $lon_col]);
    $query->condition('n.type', $type);
    $query->condition('n.status', 1);
    $query->isNotNull('l.' . $lat_col);
    $query->isNotNull('l.' . $lon_col);
    // Filter out suspicious locations directly in the query for better performance.
    // 1. 0,0 is suspicious.
    $query->condition('l.' . $lat_col, 0, '!=');
    $query->condition('l.' . $lon_col, 0, '!=');
    // 2. Habitable range filter.
    $query->condition('l.' . $lat_col, 77.75, '<=');
    $query->condition('l.' . $lat_col, -56.7, '>=');
    // 3. Known "default" coordinates that might be incorrect.
    $query->condition('l.' . $lat_col, [40.416775, 41.385064, -73.989308, -69.021414], 'NOT IN');

    $results = $query->execute();

    while ($row = $results->fetchObject()) {
      $points[] = [
        'lat' => (float) $row->{$lat_col},
        'lon' => (float) $row->{$lon_col},
        'id' => (int) $row->nid,
        'title' => $row->title,
      ];
    }

    return new JsonResponse($points);
  }

  /**
   * Returns trajectory points for a specific node.
   */
  public function getNodeTrajectory($nid) {
    $unique_points = [];
    $node = Node::load($nid);
    
    if (!$node) {
      return new JsonResponse([]);
    }

    // Try both field_location (single) and field_locations (multiple).
    $fields = ['field_location', 'field_locations'];
    
    $created = $node->getCreatedTime();
    $date_formatter = \Drupal::service('date.formatter');
    $formatted_date = $date_formatter->format($created, 'short');

    foreach ($fields as $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $values = $node->get($field_name)->getValue();
        foreach ($values as $delta => $value) {
          $lat = (float) ($value['lat'] ?? $value['value_lat'] ?? 0);
          $lon = (float) ($value['lon'] ?? $value['value_lon'] ?? 0);
          
          if ($lat && $lon && !self::isSuspiciousLocation($lat, $lon)) {
            $key = sprintf('%.6f,%.6f', $lat, $lon);
            if (!isset($unique_points[$key])) {
              $unique_points[$key] = [
                'lat' => $lat,
                'lon' => $lon,
                'index' => count($unique_points) + 1,
                'date' => $formatted_date,
              ];
            }
          }
        }
      }
    }

    return new JsonResponse(array_values($unique_points));
  }

}
