<?php

namespace Drupal\labdoo_map\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for suspicious locations report.
 */
class SuspiciousLocationsController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs a new SuspiciousLocationsController object.
   */
  public function __construct(Connection $database, DateFormatterInterface $date_formatter) {
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('date.formatter')
    );
  }

  /**
   * Displays the suspicious locations report.
   */
  public function report() {
    $header = [
      ['data' => $this->t('Title'), 'field' => 'title'],
      ['data' => $this->t('Type'), 'field' => 'type'],
      ['data' => $this->t('Author'), 'field' => 'author'],
      ['data' => $this->t('Created'), 'field' => 'created', 'sort' => 'desc'],
      ['data' => $this->t('Last edited'), 'field' => 'changed'],
      ['data' => $this->t('Coordinates')],
      ['data' => $this->t('Country')],
      ['data' => $this->t('City')],
      ['data' => $this->t('Operations')],
    ];

    // Query 1: field_location (dootronic, edoovillage)
    $query1 = $this->database->select('node_field_data', 'n');
    $query1->join('users_field_data', 'u', 'n.uid = u.uid');
    $query1->join('node__field_location', 'l', 'n.nid = l.entity_id AND l.deleted = 0');
    $query1->leftJoin('node__field_city', 'c', 'n.nid = c.entity_id AND c.deleted = 0');
    $query1->leftJoin('node__field_country', 'co', 'n.nid = co.entity_id AND co.deleted = 0');
    $query1->fields('n', ['nid', 'title', 'type', 'created', 'changed']);
    $query1->addField('u', 'name', 'author');
    $query1->addField('l', 'field_location_lat', 'lat');
    $query1->addField('l', 'field_location_lon', 'lon');
    $query1->addField('c', 'field_city_value', 'city');
    $query1->addField('co', 'field_country_value', 'country');
    $query1->where('(l.field_location_lat = 0 AND l.field_location_lon = 0) OR ABS(l.field_location_lat) > 85');

    // Query 2: field_locations (action, dootrip, hub)
    $query2 = $this->database->select('node_field_data', 'n');
    $query2->join('users_field_data', 'u', 'n.uid = u.uid');
    $query2->join('node__field_locations', 'l', 'n.nid = l.entity_id AND l.deleted = 0');
    $query2->leftJoin('node__field_city', 'c', 'n.nid = c.entity_id AND c.deleted = 0');
    $query2->leftJoin('node__field_country', 'co', 'n.nid = co.entity_id AND co.deleted = 0');
    $query2->fields('n', ['nid', 'title', 'type', 'created', 'changed']);
    $query2->addField('u', 'name', 'author');
    $query2->addField('l', 'field_locations_lat', 'lat');
    $query2->addField('l', 'field_locations_lon', 'lon');
    $query2->addField('c', 'field_city_value', 'city');
    $query2->addField('co', 'field_country_value', 'country');
    $query2->where('(l.field_locations_lat = 0 AND l.field_locations_lon = 0) OR ABS(l.field_locations_lat) > 85');

    // We can't easily use the Union with the Drupal Query builder for Pager.
    // So we'll use a manual query with UNION and then handle pager.
    
    $sql = "(" . $query1->__toString() . ") UNION (" . $query2->__toString() . ")";
    
    // Add sorting.
    $sql .= " ORDER BY created DESC";
    
    // Pagination.
    $total = $this->database->query("SELECT COUNT(*) FROM (" . $sql . ") as t")->fetchField();
    $limit = 50;
    $pager = \Drupal::service('pager.manager')->createPager($total, $limit);
    $page = $pager->getCurrentPage();
    $offset = $page * $limit;
    
    $results = $this->database->query($sql . " LIMIT $limit OFFSET $offset");

    $rows = [];
    foreach ($results as $result) {
      $rows[] = [
        'title' => Link::fromTextAndUrl($result->title, Url::fromRoute('entity.node.canonical', ['node' => $result->nid])),
        'type' => $result->type,
        'author' => $result->author,
        'created' => $this->dateFormatter->format($result->created, 'short'),
        'changed' => $this->dateFormatter->format($result->changed, 'short'),
        'coordinates' => sprintf('%f, %f', $result->lat, $result->lon),
        'country' => $result->country,
        'city' => $result->city,
        'operations' => Link::fromTextAndUrl($this->t('Edit'), Url::fromRoute('entity.node.edit_form', ['node' => $result->nid], ['attributes' => ['class' => ['button']]])),
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No suspicious locations found.'),
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}
