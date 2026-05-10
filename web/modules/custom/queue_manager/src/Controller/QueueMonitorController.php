<?php

namespace Drupal\queue_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;

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
   * Constructs a new QueueMonitorController object.
   */
  public function __construct(QueueWorkerManagerInterface $queue_worker_manager, QueueFactory $queue_factory, Connection $database) {
    $this->queueWorkerManager = $queue_worker_manager;
    $this->queueFactory = $queue_factory;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.queue_worker'),
      $container->get('queue'),
      $container->get('database')
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
      
      $worker_info = isset($queues[$queue_name]) ? $queues[$queue_name]['title'] : $this->t('No worker');

      $rows[] = [
        'name' => $queue_name,
        'title' => $worker_info,
        'items' => $count,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Queue name'),
        $this->t('Worker title'),
        $this->t('Number of items'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No queues found.'),
    ];
  }

}
