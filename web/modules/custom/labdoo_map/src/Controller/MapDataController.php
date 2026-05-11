<?php

namespace Drupal\labdoo_map\Controller;

use Drupal\Core\Controller\ControllerBase;
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

}
