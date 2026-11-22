<?php

namespace Drupal\labdoo_scraper\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Database\Query\TableSortExtender;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Controller for the scraper report.
 *
 * @phpstan-consistent-constructor
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
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request_stack service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatterInjected
   *   The date.formatter service.
   */
  public function __construct(
    Connection $database,
    protected RequestStack $requestStack,
    protected DateFormatterInterface $dateFormatterInjected,
  ) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('request_stack'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Displays the report of scrapped URLs.
   */
  public function report() {
    $build = [];

    // Add filter form.
    $build['filter_form'] = $this->formBuilder()->getForm('\Drupal\labdoo_scraper\Form\ScraperReportFilterForm');

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

    $query = $this->database->select('labdoo_scraper_results', 'r');
    $query->fields('r');

    // Apply filters.
    $request = $this->requestStack->getCurrentRequest();
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

    $sort = new TableSortExtender($query, $this->database);
    $sort->orderByHeader($header);
    $query = new PagerSelectExtender($sort, $this->database);
    $query->limit(50);

    $results = $query->execute();

    $rows = [];
    foreach ($results as $result) {
      $exists_text = $result->exists ? $this->t('Yes') : $this->t('No');

      $actions = [];
      if (!$result->exists) {
        $slug = $result->slug;
        // Strip leading slash for redirect module if present.
        $source_path = ltrim($slug, '/');

        $redirect_url = Url::fromUserInput('/admin/config/search/redirect/add', [
          'query' => [
            'source' => $source_path,
        // Return here after.
            'destination' => '/admin/reports/labdoo-scraper',
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
        $this->dateFormatterInjected->format($result->created, 'short'),
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
