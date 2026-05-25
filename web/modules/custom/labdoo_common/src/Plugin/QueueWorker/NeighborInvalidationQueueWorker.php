<?php

namespace Drupal\labdoo_common\Plugin\QueueWorker;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface;
use Drupal\labdoo_hub\Service\Repository\HubRepositoryInterface;
use Drupal\queue_manager\Model\QueueDataModel;
use Drupal\queue_manager\Model\QueueDataModelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker that invalidates neighbor caches.
 *
 * @QueueWorker(
 *   id = "labdoo_common_neighbor_invalidation",
 *   title = @Translation("Labdoo Neighbor Cache Invalidation"),
 *   cron = {"time" = 30}
 * )
 */
class NeighborInvalidationQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The common repository.
   *
   * @var \Drupal\labdoo_common\Service\Repository\CommonRepository
   */
  protected CommonRepository $commonRepository;

  /**
   * The dootronic repository.
   *
   * @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface|null
   */
  protected ?DootronicRepositoryInterface $dootronicRepository;

  /**
   * The edoovillage repository.
   *
   * @var \Drupal\labdoo_edoovillage\Service\Repository\EdooVillageRepositoryInterface|null
   */
  protected ?EdooVillageRepositoryInterface $edoovillageRepository;

  /**
   * The hub repository.
   *
   * @var \Drupal\labdoo_hub\Service\Repository\HubRepositoryInterface|null
   */
  protected ?HubRepositoryInterface $hubRepository;

  /**
   * {@inheritDoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    CommonRepository $commonRepository,
    ?DootronicRepositoryInterface $dootronicRepository = NULL,
    ?EdooVillageRepositoryInterface $edoovillageRepository = NULL,
    ?HubRepositoryInterface $hubRepository = NULL
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerChannelFactory->get('labdoo_common');
    $this->commonRepository = $commonRepository;
    $this->dootronicRepository = $dootronicRepository;
    $this->edoovillageRepository = $edoovillageRepository;
    $this->hubRepository = $hubRepository;
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
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('labdoo_common.repository.common'),
      $container->has('labdoo_dootronics.repository') ? $container->get('labdoo_dootronics.repository') : NULL,
      $container->has('labdoo_edoovillage.repository') ? $container->get('labdoo_edoovillage.repository') : NULL,
      $container->has('labdoo_hub.repository') ? $container->get('labdoo_hub.repository') : NULL
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $queueData = $this->checkData($data);
    $item = $queueData->getData();

    $entityType = $item['entity_type'] ?? NULL;
    $entityId = $item['entity_id'] ?? NULL;
    $label = $item['label'] ?? NULL;

    if (!$entityType) {
      return;
    }

    switch ($entityType) {
      case 'dootronic':
        $this->invalidateDootronicNeighbors($entityId, $label);
        break;

      case 'edoovillage':
        $this->invalidateGenericNeighbors($entityId, 'edoovillage', $this->edoovillageRepository);
        break;

      case 'hub':
        $this->invalidateGenericNeighbors($entityId, 'hub', $this->hubRepository);
        break;
    }
  }

  /**
   * Invalidate dootronic neighbors.
   */
  protected function invalidateDootronicNeighbors(?int $id, ?string $label): void {
    if (!$this->dootronicRepository) {
      return;
    }

    if ($label === NULL && $id !== NULL) {
      $entity = $this->dootronicRepository->load($id);
      if ($entity) {
        $label = $entity->label();
      }
    }

    if ($label === NULL) {
      return;
    }

    $prevNodeId = $this->dootronicRepository->getPreviousDootronicByTitle($label);
    if ($prevNodeId > 0 && $prevNodeId != $id) {
      $this->dootronicRepository->invalidateCache($prevNodeId);
    }

    $nextNodeId = $this->dootronicRepository->getNextDootronicByTitle($label);
    if ($nextNodeId > 0 && $nextNodeId != $id) {
      $this->dootronicRepository->invalidateCache($nextNodeId);
    }
  }

  /**
   * Invalidate generic neighbors using common repository.
   */
  protected function invalidateGenericNeighbors(?int $id, string $type, $repository): void {
    if (!$id || !$repository) {
      return;
    }

    $prevNodeId = $this->commonRepository->getPreviousEntity($id, $type);
    if ($prevNodeId > 0 && $prevNodeId != $id) {
      $repository->invalidateCache($prevNodeId);
    }

    $nextNodeId = $this->commonRepository->getNextEntity($id, $type);
    if ($nextNodeId > 0 && $nextNodeId != $id) {
      $repository->invalidateCache($nextNodeId);
    }
  }

  /**
   * Checks the input data.
   */
  protected function checkData(array $data): QueueDataModelInterface {
    $queueDataModel = new QueueDataModel();
    $queueDataModel->__unserialize($data);
    return $queueDataModel;
  }

}
