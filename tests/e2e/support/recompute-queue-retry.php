<?php

/**
 * @file
 * Exercises real cron/queue retention with controlled recompute dependencies.
 */

declare(strict_types=1);

use Drupal\Core\Cron;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Site\Settings;
use Drupal\labdoo_common\Event\InvalidateCacheTagsEvent;
use Drupal\labdoo_dootrip\Plugin\QueueWorker\DootripCapacityQueueWorker;
use Drupal\labdoo_dootrip\Service\Compute\DootripCompute;
use Drupal\labdoo_dootrip\Service\Queue\Feeder\DootripCapacityQueueFeeder;
use Drupal\labdoo_dootrip\Service\Repository\DootripRepository;
use Drupal\labdoo_dootronics\Plugin\QueueWorker\DootronicRecomputeQueueWorker;
use Drupal\labdoo_dootronics\Service\Compute\DootronicCompute;
use Drupal\labdoo_dootronics\Service\Queue\Feeder\DootronicRecomputeQueueFeeder;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepository;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\queue_manager\Service\QueueHelper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

require __DIR__ . '/harden.php';
if (realpath(\Drupal::root()) !== realpath(dirname(__DIR__, 3) . '/web')
  || Settings::get('labdoo_e2e_state') !== dirname(__DIR__, 2) . '/upgrade/.local/state') {
  throw new RuntimeException('Use this checkout\'s isolated regression clone.');
}
$db = \Drupal::database();
if (!$db->schema()->tableExists('queue')) {
  throw new RuntimeException('The isolated clone must already have its queue schema.');
}
$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new LogicException($message);
  }
};
$loggerFactory = new class implements LoggerChannelFactoryInterface {

  /**
   * {@inheritdoc}
   */
  public function get($channel) {
    return new LoggerChannel('queue_retry_fixture');
  }

  /**
   * {@inheritdoc}
   */
  public function addLogger(LoggerInterface $logger, $priority = 0) {}

};
$passed = [];
$queues = [];
try {
  foreach (['dootronic', 'dootrip'] as $kind) {
    $name = 'labdoo_e2e_retry_' . bin2hex(random_bytes(8));
    $queue = new DatabaseQueue($name, $db);
    $queues[] = $queue;
    $queueFactory = new class($queue) extends QueueFactory {

      /**
       * Builds controlled test dependencies.
       */
      public function __construct(private QueueInterface $scope) {}

      /**
       * {@inheritdoc}
       */
      public function get($name, $reliable = FALSE) {
        return $this->scope;
      }

    };
    $helper = new class($queueFactory, $db, $name) extends QueueHelper {

      /**
       * Builds controlled test dependencies.
       */
      public function __construct(QueueFactory $factory, $database, private string $scope) {
        parent::__construct($factory, $database);
      }

      /**
       * {@inheritdoc}
       */
      public function enqueueData(string $queueId, array $data): ?QueueInterface {
        return parent::enqueueData($this->scope, $data);
      }

    };
    // The entity is unsaved; computation and storage are controlled.
    $node = Node::create(['type' => $kind, 'nid' => 2147483000, 'uid' => 17]);
    if ($kind === 'dootronic') {
      $repository = new class($node) extends DootronicRepository {
        /**
         * Whether the controlled write succeeds.
         */
        public bool $allowSave = FALSE;
        /**
         * Whether the controlled entity is present.
         */
        public bool $present = TRUE;
        /**
         * The number of attempted writes.
         */
        public int $attempts = 0;

        /**
         * Builds controlled test dependencies.
         */
        public function __construct(private NodeInterface $fixture) {}

        /**
         * {@inheritdoc}
         */
        public function load(int $dootronicId): ?NodeInterface {
          return $this->present ? $this->fixture : NULL;
        }

        /**
         * {@inheritdoc}
         */
        public function saveEntity(EntityInterface $dootronic): bool {
          $this->attempts++;
          return $this->allowSave;
        }

      };
      $compute = new class extends DootronicCompute {

        /**
         * Builds controlled test dependencies.
         */
        public function __construct() {}

        /**
         * {@inheritdoc}
         */
        public function computeEdooVillageData(EntityInterface $entity): void {}

        /**
         * {@inheritdoc}
         */
        public function computeHubData(EntityInterface $entity): void {}

        /**
         * {@inheritdoc}
         */
        public function computeRelatedDootrips(EntityInterface &$dootronic, array $originalDootripIds = []): void {}

      };
      $feeder = new DootronicRecomputeQueueFeeder($helper);
      $workerClass = DootronicRecomputeQueueWorker::class;
    }
    else {
      $repository = new class($node) extends DootripRepository {
        /**
         * Whether the controlled write succeeds.
         */
        public bool $allowSave = FALSE;
        /**
         * Whether the controlled entity is present.
         */
        public bool $present = TRUE;
        /**
         * The number of attempted writes.
         */
        public int $attempts = 0;

        /**
         * Builds controlled test dependencies.
         */
        public function __construct(private NodeInterface $fixture) {}

        /**
         * {@inheritdoc}
         */
        public function load(int $dootripId): ?NodeInterface {
          return $this->present ? $this->fixture : NULL;
        }

        /**
         * {@inheritdoc}
         */
        public function saveEntity(EntityInterface $dootrip): bool {
          $this->attempts++;
          return $this->allowSave;
        }

      };
      $compute = new class extends DootripCompute {

        /**
         * Builds controlled test dependencies.
         */
        public function __construct() {}

        /**
         * {@inheritdoc}
         */
        public function computeDootripCapacity(EntityInterface &$dootrip): void {}

        /**
         * {@inheritdoc}
         */
        public function computeRelatedDootronics(EntityInterface &$dootrip, array $originalDootronicIds = []): void {}

        /**
         * {@inheritdoc}
         */
        public function computeEdoovillagesAssigned(EntityInterface &$dootrip): void {}

      };
      $feeder = new DootripCapacityQueueFeeder($helper);
      $workerClass = DootripCapacityQueueWorker::class;
    }
    $nativeCount = (int) $db->select('queue', 'q')->condition('name', $feeder->getQueueId())->countQuery()->execute()->fetchField();
    $events = [];
    $dispatcher = new EventDispatcher();
    $dispatcher->addListener(InvalidateCacheTagsEvent::EVENT_NAME, static function (InvalidateCacheTagsEvent $event) use (&$events): void {
      $events[] = $event->getCacheTags();
    });
    $worker = new $workerClass([], $feeder->getQueueId(), ['cron' => ['time' => 60]], $loggerFactory, $compute, $repository, $dispatcher);
    // Only this protected single-queue processor is invoked, never cron::run().
    $cron = new Cron(\Drupal::moduleHandler(), \Drupal::lock(), $queueFactory, \Drupal::state(), \Drupal::service('account_switcher'), new NullLogger(), \Drupal::service('plugin.manager.queue_worker'), \Drupal::time(), []);
    $process = new ReflectionMethod($cron, 'processQueue');
    $feeder->feedQueue($node);
    if ($kind === 'dootrip') {
      $feeder->feedQueue($node);
      $assert($queue->numberOfItems() === 1, 'Capacity deduplication changed.');
    }
    $before = $db->select('queue', 'q')->fields('q')->condition('name', $name)->execute()->fetchAssoc();
    $process->invoke($cron, $queue, $worker);
    $failed = $db->select('queue', 'q')->fields('q')->condition('name', $name)->execute()->fetchAssoc();
    $assert($failed && $failed['item_id'] === $before['item_id'] && $failed['data'] === $before['data'], 'Failed storage lost or changed the queued item.');
    $assert((int) $failed['expire'] > 0 && $repository->attempts === 1 && $events === [], 'Failure was not retained under its lease without cache events.');
    $repository->allowSave = TRUE;
    // Release this owned failed lease instead of waiting 60 seconds to retry.
    $queue->releaseItem((object) ['item_id' => $failed['item_id']]);
    $process->invoke($cron, $queue, $worker);
    $assert($queue->numberOfItems() === 0 && $repository->attempts === 2 && count($events) === 1, 'Successful retry did not consume exactly once.');
    $repository->present = FALSE;
    $feeder->feedQueue($node);
    $process->invoke($cron, $queue, $worker);
    $assert($queue->numberOfItems() === 0 && $repository->attempts === 2 && count($events) === 2, 'Deleted entity no longer terminates with cache invalidation.');
    $assert((int) $db->select('queue', 'q')->condition('name', $feeder->getQueueId())->countQuery()->execute()->fetchField() === $nativeCount, 'A native recompute queue was changed.');
    $passed[] = $kind . ': failed save retained, retry consumed, deleted entity terminal';
  }
  echo 'QUEUE_RETRY_JSON:' . json_encode(['passed' => count($passed), 'cases' => $passed], JSON_THROW_ON_ERROR);
}
finally {
  foreach ($queues as $queue) {
    $queue->deleteQueue();
  }
}
