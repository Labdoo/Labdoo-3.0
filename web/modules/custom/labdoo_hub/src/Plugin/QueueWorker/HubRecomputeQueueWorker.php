<?php

namespace Drupal\labdoo_hub\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\labdoo_common\Event\InvalidateCacheTagsEvent;
use Drupal\labdoo_hub\Service\Compute\HubComputeInterface;
use Drupal\labdoo_hub\Service\Repository\HubRepositoryInterface;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\queue_manager\Exception\EmptyQueueItemException;
use Drupal\queue_manager\Model\QueueDataModel;
use Drupal\queue_manager\Model\QueueDataModelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Queue worker that processes the hub recompute.
 *
 * @QueueWorker(
 *   id = "labdoo_hub_recompute",
 *   title = @Translation("Recompute the hub data"),
 *   cron = {"time" = 10}
 * )
 */
class HubRecomputeQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The hub compute service.
   *
   * @var \Drupal\labdoo_hub\Service\Compute\HubComputeInterface
   */
  protected HubComputeInterface $hubCompute;

  /**
   * The hub repository.
   *
   * @var \Drupal\labdoo_hub\Service\Repository\HubRepositoryInterface
   */
  protected HubRepositoryInterface $hubRepository;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * {@inheritDoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    HubComputeInterface $hubCompute,
    HubRepositoryInterface $hubRepository,
    EventDispatcherInterface $eventDispatcher
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerChannelFactory->get('bb_valentina');
    $this->hubCompute = $hubCompute;
    $this->hubRepository = $hubRepository;
    $this->eventDispatcher = $eventDispatcher;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    /** @var \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory */
    $loggerChannelFactory = $container->get('logger.factory');
    /** @var \Drupal\labdoo_hub\Service\Compute\HubComputeInterface $hubCompute */
    $hubCompute = $container->get('labdoo_hub.compute');
    /** @var \Drupal\labdoo_hub\Service\Repository\HubRepositoryInterface $hubRepository */
    $hubRepository = $container->get('labdoo_hub.repository');
    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
    $eventDispatcher = $container->get('event_dispatcher');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $loggerChannelFactory,
      $hubCompute,
      $hubRepository,
      $eventDispatcher
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    try {
      $data = $this->checkData($data);
      $queueData = $data->getData();
      $hubId = NULL;
      $uid = NULL;

      if (is_array($queueData)) {
        $hubId = $queueData['id'] ?? (reset($queueData) ?: NULL);
        $uid = $queueData['uid'] ?? NULL;
      }
      else {
        $hubId = $queueData;
      }

      if ($hubId === NULL) {
        throw new \Exception('Invalid hub ID');
      }

      $hub = $this->hubRepository->load((int) $hubId);
      if ($hub === NULL) {
        $this->clearCachetagById((int) $hubId, (int) $uid);
        return;
      }

      // Flag to prevent recursive enqueuing during background recompute.
      $hub->skip_geocoding_enqueue = TRUE;
      $hub->skip_recompute_enqueue = TRUE;

      // Recompute logic (currently none for Hub, but we clear tags).
      $this->clearCachetag($hub);
    }
    catch (EmptyQueueItemException | InvalidDataTypeException $exception) {
      $this->logger->warning(sprintf(
        'Removing item from queue %s: %s',
        $this->getPluginId(),
        $exception->getMessage()
      ));
      // By not re-throwing, the item is removed from the queue.
    }
    catch (\Exception $exception) {
      $errorMessage = sprintf(
        'Error processing hub: %s',
        $exception->getMessage()
      );
      $this->logger->error($errorMessage);

      throw $exception;
    }
  }

  /**
   * Invalidate the node type cache tag.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  protected function clearCachetag(EntityInterface $entity): void {
    $this->clearCachetagById((int) $entity->id(), (int) $entity->getOwnerId());
  }

  /**
   * Invalidate the node type cache tag by ID and UID.
   *
   * @param int $id
   *   The entity ID.
   * @param int|null $uid
   *   The owner ID.
   */
  protected function clearCachetagById(int $id, ?int $uid = NULL): void {
    $tags = [
      'hub_chart',
      'node:hub',
    ];

    if ($uid !== NULL) {
      $tags[] = sprintf('hub:%d:%d', $id, $uid);
    }

    $event = new InvalidateCacheTagsEvent();
    $event->setCacheTags($tags);

    $this->eventDispatcher->dispatch($event, InvalidateCacheTagsEvent::EVENT_NAME);
  }

  /**
   * Checks the input data.
   *
   * @param array $data
   *   Input data.
   *
   * @return \Drupal\queue_manager\Model\QueueDataModelInterface
   *   The queue data model.
   *
   * @throws \Drupal\queue_manager\Exception\EmptyQueueItemException
   * @throws \Exception
   */
  protected function checkData(array $data): QueueDataModelInterface {
    if (!count($data)) {
      throw new EmptyQueueItemException($this->getBaseId());
    }

    $queueDataModel = new QueueDataModel();
    $queueDataModel->__unserialize($data);

    return $queueDataModel;
  }

}
