<?php

namespace Drupal\queue_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Controller for monitoring queues.
 */
class QueueMonitorController extends ControllerBase {

  /**
   * The queue worker manager.
   *
   * @var \Drupal\Core\Queue\QueueWorkerManagerInterface
   */
  protected $queueWorkerManager;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

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
   * Constructs a new QueueMonitorController object.
   */
  public function __construct(QueueWorkerManagerInterface $queue_worker_manager, QueueFactory $queue_factory, Connection $database, DateFormatterInterface $date_formatter) {
    $this->queueWorkerManager = $queue_worker_manager;
    $this->queueFactory = $queue_factory;
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.queue_worker'),
      $container->get('queue'),
      $container->get('database'),
      $container->get('date.formatter')
    );
  }

  /**
   * Lists all queues and their item counts.
   */
  public function listQueues() {
    $queues = $this->queueWorkerManager->getDefinitions();
    $rows = [];

    // Get all queues from the database to find those without workers.
    $query = $this->database->select('queue', 'q')
      ->fields('q', ['name'])
      ->distinct();
    $db_queues = $query->execute()->fetchCol();

    $all_queue_names = array_unique(array_merge(array_keys($queues), $db_queues));
    asort($all_queue_names);

    foreach ($all_queue_names as $queue_name) {
      $queue = $this->queueFactory->get($queue_name);
      $count = $queue->numberOfItems();

      // Count expired items (often indicates failure).
      $expired_count = $this->database->select('queue', 'q')
        ->condition('name', $queue_name)
        ->condition('expire', 0, '>')
        ->countQuery()
        ->execute()
        ->fetchField();

      // Get last execution and errors from ultimate_cron_log if table exists.
      $last_run = $this->t('Never');
      $log_errors = 0;
      if ($this->database->schema()->tableExists('ultimate_cron_log')) {
        $job_name = 'ultimate_cron_queue_' . $queue_name;
        $log_query = $this->database->select('ultimate_cron_log', 'l')
          ->fields('l', ['start_time', 'severity'])
          ->condition('name', $job_name)
          ->orderBy('start_time', 'DESC')
          ->range(0, 1)
          ->execute()
          ->fetchObject();

        if ($log_query) {
          $last_run = $this->dateFormatter->format((int) $log_query->start_time, 'short');
        }

        // Count logs with error severity in the last 24 hours.
        $log_errors = $this->database->select('ultimate_cron_log', 'l')
          ->condition('name', $job_name)
          ->condition('severity', 3, '<=') // ERROR or worse
          ->condition('start_time', time() - 86400, '>=')
          ->countQuery()
          ->execute()
          ->fetchField();
      }

      $worker_info = isset($queues[$queue_name]) ? $queues[$queue_name]['title'] : $this->t('No worker');

      $rows[] = [
        'name' => $queue_name,
        'title' => $worker_info,
        'items' => $count,
        'expired' => $expired_count,
        'last_run' => $last_run,
        'log_errors' => $log_errors,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Queue name'),
        $this->t('Worker title'),
        $this->t('Number of items'),
        $this->t('Expired items'),
        $this->t('Last run'),
        $this->t('Errors (24h)'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No queues found.'),
    ];
  }

}
