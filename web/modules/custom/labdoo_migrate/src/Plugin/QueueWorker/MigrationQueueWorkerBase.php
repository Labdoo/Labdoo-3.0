<?php

namespace Drupal\labdoo_migrate\Plugin\QueueWorker;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface;
use Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface;
use Drupal\queue_manager\Exception\EmptyQueueItemException;
use Drupal\queue_manager\Model\QueueDataModel;
use Drupal\queue_manager\Model\QueueDataModelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for migration queue workers.
 */
abstract class MigrationQueueWorkerBase extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The destination node repository.
   *
   * @var \Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface
   */
  protected DestinationRepositoryInterface $nodeDestinationRepository;

  /**
   * The destination user repository.
   *
   * @var \Drupal\labdoo_migrate\Services\DestinationContent\DestinationRepositoryInterface
   */
  protected DestinationRepositoryInterface $userDestinationRepository;

  /**
   * The source node repository.
   *
   * @var \Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface
   */
  protected SourceRepositoryInterface $nodeSourceRepository;

  /**
   * The source user repository.
   *
   * @var \Drupal\labdoo_migrate\Services\SourceContent\SourceRepositoryInterface
   */
  protected SourceRepositoryInterface $userSourceRepository;

  /**
   * {@inheritDoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    DestinationRepositoryInterface $nodeDestinationRepository,
    DestinationRepositoryInterface $userDestinationRepository,
    SourceRepositoryInterface $nodeSourceRepository,
    SourceRepositoryInterface $userSourceRepository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerChannelFactory->get('labdoo_migrate');
    $this->nodeDestinationRepository = $nodeDestinationRepository;
    $this->userDestinationRepository = $userDestinationRepository;
    $this->nodeSourceRepository = $nodeSourceRepository;
    $this->userSourceRepository = $userSourceRepository;
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
      $container->get('labdoo_migrate.destination_content.repository.node'),
      $container->get('labdoo_migrate.destination_content.repository.user'),
      $container->get('labdoo_migrate.source_content.repository.node'),
      $container->get('labdoo_migrate.source_content.repository.user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    try {
      $queueDataModel = $this->checkData($data);
      $itemData = $queueDataModel->getData();

      $contentType = $itemData['content_type'] ?? NULL;
      $entityId = $itemData['entity_id'] ?? NULL;
      $mapping = $itemData['mapping'] ?? [];
      $dryRun = $itemData['dry_run'] ?? FALSE;
      $mode = $itemData['mode'] ?? 'create';
      $destinationContentType = $itemData['destination_content_type'] ?? $contentType;

      if (!$contentType || !$entityId) {
        throw new \Exception('Missing required migration data (content_type or entity_id)');
      }

      $isUser = ($contentType === 'user');
      $sourceRepository = $isUser ? $this->userSourceRepository : $this->nodeSourceRepository;
      $destinationRepository = $isUser ? $this->userDestinationRepository : $this->nodeDestinationRepository;

      // Ensure some defaults for destination repository.
      if (isset($itemData['override'])) {
        $destinationRepository->setOverrideMode($itemData['override']);
      }

      if (isset($itemData['total_count'])) {
        $destinationRepository->setTotalCount($itemData['total_count']);
      }

      if ($mode === 'create') {
        $sourceEntity = $sourceRepository->getEntity($contentType, $mapping, $entityId);
        if (empty($sourceEntity)) {
          $this->logger->warning(sprintf('Source entity %s of type %s not found.', $entityId, $contentType));
          return;
        }

        $destinationRepository->createEntities(
          [$entityId => $sourceEntity],
          $mapping,
          $destinationContentType,
          $dryRun
        );
      }
      elseif ($mode === 'update') {
        $destinationEntityId = $itemData['destination_entity_id'] ?? NULL;
        if (!$destinationEntityId) {
           throw new \Exception('Missing destination_entity_id for update mode');
        }

        $sourceEntity = $sourceRepository->getEntity($contentType, $mapping, $entityId);
        if (empty($sourceEntity)) {
           $this->logger->warning(sprintf('Source entity %s of type %s not found for update.', $entityId, $contentType));
           return;
        }

        // For updates, we need the destination entities.
        // The destinationRepository::getEntities normally takes array of content types and array of nids.
        $destinationEntities = $destinationRepository->getEntities([$destinationContentType], [$destinationEntityId]);
        if (empty($destinationEntities)) {
          $this->logger->warning(sprintf('Destination entity %s of type %s not found for update.', $destinationEntityId, $destinationContentType));
          return;
        }

        $destinationRepository->updateEntities(
          [$entityId => $sourceEntity],
          $mapping,
          $destinationEntities,
          $dryRun
        );
      }
    }
    catch (EmptyQueueItemException $exception) {
      $this->logger->warning($exception->getMessage());
    }
    catch (\Exception $exception) {
      $errorMessage = sprintf(
        'Error processing migration item: %s',
        $exception->getMessage()
      );
      $this->logger->error($errorMessage);
      throw $exception;
    }
  }

  /**
   * Checks the input data.
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
