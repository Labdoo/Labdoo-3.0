<?php

namespace Drupal\labdoo_edoovillage\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\labdoo_common\Event\InvalidateCacheTagsEvent;
use Drupal\labdoo_edoovillage\Service\Compute\EdooVillageComputeInterface;
use Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\queue_manager\Exception\EmptyQueueItemException;
use Drupal\queue_manager\Model\QueueDataModel;
use Drupal\queue_manager\Model\QueueDataModelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Queue worker that processes the edoovillage recompute.
 *
 * @QueueWorker(
 *   id = "labdoo_edoovillage_recompute",
 *   title = @Translation("Recompute the edoovillage data"),
 *   cron = {"time" = 10}
 * )
 */
class EdooVillageRecomputeQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The edoovillage compute service.
   *
   * @var \Drupal\labdoo_edoovillage\Service\Compute\EdooVillageComputeInterface
   */
  protected EdooVillageComputeInterface $edoovillageCompute;

  /**
   * The edoovillage repository.
   *
   * @var \Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface
   */
  protected EdooVillageRepositoryInterface $edoovillageRepository;

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
    EdooVillageComputeInterface $edoovillageCompute,
    EdooVillageRepositoryInterface $edoovillageRepository,
    EventDispatcherInterface $eventDispatcher
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerChannelFactory->get('bb_valentina');
    $this->edoovillageCompute = $edoovillageCompute;
    $this->edoovillageRepository = $edoovillageRepository;
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
    /** @var \Drupal\labdoo_edoovillage\Service\Compute\EdooVillageComputeInterface $edoovillageCompute */
    $edoovillageCompute = $container->get('labdoo_edoovillage.compute');
    /** @var \Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface $edoovillageRepository */
    $edoovillageRepository = $container->get('labdoo_edoovillage.repository');
    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
    $eventDispatcher = $container->get('event_dispatcher');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $loggerChannelFactory,
      $edoovillageCompute,
      $edoovillageRepository,
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
      $edoovillageId = NULL;
      $uid = NULL;

      if (is_array($queueData)) {
        $edoovillageId = $queueData['id'] ?? (reset($queueData) ?: NULL);
        $uid = $queueData['uid'] ?? NULL;
      }
      else {
        $edoovillageId = $queueData;
      }

      if ($edoovillageId === NULL) {
        throw new \Exception('Invalid edoovillage ID');
      }

      $edoovillage = $this->edoovillageRepository->load((int) $edoovillageId);
      if ($edoovillage === NULL) {
        $this->clearCachetagById((int) $edoovillageId, (int) $uid);
        return;
      }

      // Flag to prevent recursive enqueuing during background recompute.
      $edoovillage->skip_geocoding_enqueue = TRUE;
      $edoovillage->skip_recompute_enqueue = TRUE;

      // Recompute logic (currently none for EdooVillage, but we clear tags).
      $this->clearCachetag($edoovillage);
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
        'Error processing edoovillage: %s',
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
      'edoovillages_chart',
      'node:edoovillage',
    ];

    if ($uid !== NULL) {
      $tags[] = sprintf('edoovillage:%d:%d', $id, $uid);
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
