<?php

namespace Drupal\labdoo_dootronics\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\labdoo_common\Event\InvalidateCacheTagsEvent;
use Drupal\labdoo_dootronics\Service\Compute\DootronicComputeInterface;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\queue_manager\Exception\EmptyQueueItemException;
use Drupal\queue_manager\Model\QueueDataModel;
use Drupal\queue_manager\Model\QueueDataModelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Queue worker that processes dootronic heavy recomputations.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @QueueWorker(
 *   id = "labdoo_dootronics_recompute",
 *   title = @Translation("Recompute heavy dootronics fields"),
 *   cron = {"time" = 20}
 * )
 */
class DootronicRecomputeQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The dootronic compute service.
   *
   * @var \Drupal\labdoo_dootronics\Service\Compute\DootronicComputeInterface
   */
  protected DootronicComputeInterface $dootronicCompute;

  /**
   * The dootronic repository.
   *
   * @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface
   */
  protected DootronicRepositoryInterface $dootronicRepository;

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
    DootronicComputeInterface $dootronicCompute,
    DootronicRepositoryInterface $dootronicRepository,
    EventDispatcherInterface $eventDispatcher
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerChannelFactory->get('labdoo_dootronics');
    $this->dootronicCompute = $dootronicCompute;
    $this->dootronicRepository = $dootronicRepository;
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
    /** @var \Drupal\labdoo_dootronics\Service\Compute\DootronicComputeInterface $dootronicCompute */
    $dootronicCompute = $container->get('labdoo_dootronics.compute');
    /** @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository */
    $dootronicRepository = $container->get('labdoo_dootronics.repository');
    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher */
    $eventDispatcher = $container->get('event_dispatcher');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $loggerChannelFactory,
      $dootronicCompute,
      $dootronicRepository,
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
      $dootronicId = NULL;
      $uid = NULL;

      if (is_array($queueData)) {
        $dootronicId = $queueData['id'] ?? (reset($queueData) ?: NULL);
        $uid = $queueData['uid'] ?? NULL;
      }
      else {
        $dootronicId = $queueData;
      }

      if ($dootronicId === NULL) {
        throw new \Exception('Invalid dootronic ID');
      }
      if ($dootronicId instanceof EntityInterface) {
        $dootronicId = $dootronicId->id();
      }

      $dootronic = $this->dootronicRepository->load((int) $dootronicId);
      if ($dootronic === NULL) {
        // If entity is deleted, we still clear the cache.
        $this->clearCachetagById((int) $dootronicId, (int) $uid);
        return;
      }

      // Flag to prevent recursive enqueuing during background recompute.
      $dootronic->skip_geocoding_enqueue = TRUE;
      $dootronic->skip_recompute_enqueue = TRUE;

      // Disable geocoder_field processing for this request.
      \Drupal::request()->attributes->set('geocoder_presave_disabled', TRUE);

      $this->dootronicCompute->computeEdooVillageData($dootronic);
      $this->dootronicCompute->computeHubData($dootronic);
      $this->dootronicCompute->computeRelatedDootrips($dootronic);
      $this->dootronicRepository->saveEntity($dootronic);
      $this->clearCachetag($dootronic);
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
        'Error processing dootronic: %s',
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
      'dootronics_chart',
      'node:dootronic',
    ];

    if ($uid !== NULL) {
      $tags[] = sprintf('dootronic:%d:%d', $id, $uid);
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
