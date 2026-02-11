<?php

namespace Drupal\labdoo_scraper\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * Controller for the scraper report.
 */
class ScraperReportController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new ScraperReportController object.
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
   * Displays the report of scrapped URLs.
   */
  public function report() {
    $build = [];

    // Add filter form.
    $build['filter_form'] = \Drupal::formBuilder()->getForm('\Drupal\labdoo_scraper\Form\ScraperReportFilterForm');

    $header = [
      ['data' => $this->t('ID'), 'field' => 'id', 'sort' => 'desc'],
      ['data' => $this->t('Source URL'), 'field' => 'source_url'],
      ['data' => $this->t('Discovered URL'), 'field' => 'discovered_url'],
      ['data' => $this->t('Slug'), 'field' => 'slug'],
      ['data' => $this->t('Exists'), 'field' => 'exists'],
      ['data' => $this->t('Entity Type'), 'field' => 'entity_type'],
      ['data' => $this->t('Entity ID'), 'field' => 'entity_id'],
      ['data' => $this->t('Created'), 'field' => 'created'],
      ['data' => $this->t('Actions')],
    ];

    $query = $this->database->select('labdoo_scraper_results', 'r')
      ->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
      ->extend('\Drupal\Core\Database\Query\TableSortExtender');
    $query->fields('r');

    // Apply filters.
    $request = \Drupal::request();
    if ($source_url = $request->query->get('source_url')) {
      $query->condition('source_url', '%' . $this->database->escapeLike($source_url) . '%', 'LIKE');
    }
    if ($slug = $request->query->get('slug')) {
      $query->condition('slug', '%' . $this->database->escapeLike($slug) . '%', 'LIKE');
    }
    $exists = $request->query->get('exists');
    if ($exists !== NULL && $exists !== '') {
      $query->condition('exists', $exists);
    }
    if ($entity_type = $request->query->get('entity_type')) {
      $query->condition('entity_type', $entity_type);
    }

    $query->limit(50);
    $query->orderByHeader($header);

    $results = $query->execute();

    $rows = [];
    foreach ($results as $result) {
      $exists_text = $result->exists ? $this->t('Yes') : $this->t('No');
      
      $actions = [];
      if (!$result->exists) {
        $slug = $result->slug;
        // Strip leading slash for redirect module if present
        $source_path = ltrim($slug, '/');
        
        $redirect_url = Url::fromUserInput('/admin/config/search/redirect/add', [
          'query' => [
            'source' => $source_path,
            'destination' => '/admin/reports/labdoo-scraper', // Return here after
          ],
        ]);
        
        $actions[] = [
          '#type' => 'link',
          '#title' => $this->t('Add redirect'),
          '#url' => $redirect_url,
          '#attributes' => [
            'class' => ['button', 'button--small'],
          ],
        ];
      }

      $rows[] = [
        $result->id,
        $result->source_url,
        $result->discovered_url,
        $result->slug,
        [
          'data' => $exists_text,
          'class' => [$result->exists ? 'color-success' : 'color-error'],
        ],
        $result->entity_type,
        $result->entity_id,
        \Drupal::service('date.formatter')->format($result->created, 'short'),
        [
          'data' => $actions,
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No results found.'),
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}
