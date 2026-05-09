<?php

namespace Drupal\mini_wiki\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\labdoo_common\Event\InvalidateCacheTagsEvent;
use Drupal\mini_wiki\Service\MiniWikiRepository;
use Drupal\queue_manager\Exception\EmptyQueueItemException;
use Drupal\queue_manager\Model\QueueDataModel;
use Drupal\queue_manager\Model\QueueDataModelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Queue worker that processes the mini wiki recompute.
 *
 * @QueueWorker(
 *   id = "mini_wiki_recompute",
 *   title = @Translation("Recompute the mini wiki data"),
 * )
 */
class MiniWikiRecomputeQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The mini wiki repository.
   *
   * @var \Drupal\mini_wiki\Service\MiniWikiRepository
   */
  protected MiniWikiRepository $miniWikiRepository;

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
    MiniWikiRepository $miniWikiRepository,
    EventDispatcherInterface $eventDispatcher
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerChannelFactory->get('bb_valentina');
    $this->miniWikiRepository = $miniWikiRepository;
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
    /** @var \Drupal\mini_wiki\Service\MiniWikiRepository $miniWikiRepository */
    $miniWikiRepository = $container->get('mini_wiki.repository');
    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
    $eventDispatcher = $container->get('event_dispatcher');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $loggerChannelFactory,
      $miniWikiRepository,
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
      $entityId = NULL;
      $uid = NULL;

      if (is_array($queueData)) {
        $entityId = $queueData['id'] ?? (reset($queueData) ?: NULL);
        $uid = $queueData['uid'] ?? NULL;
      }
      else {
        $entityId = $queueData;
      }

      if ($entityId === NULL) {
        throw new \Exception('Invalid mini wiki ID');
      }

      $entity = $this->miniWikiRepository->load((int) $entityId);
      if ($entity === NULL) {
        $this->clearCachetagById((int) $entityId, (int) $uid);
        return;
      }

      $this->clearCachetag($entity);
    }
    catch (EmptyQueueItemException $exception) {
      $this->logger->warning($exception->getMessage());
    }
    catch (\Exception $exception) {
      $errorMessage = sprintf(
        'Error processing mini wiki: %s',
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
      'wiki-page:list-date',
      sprintf('wiki-page:%d', $id),
    ];

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
