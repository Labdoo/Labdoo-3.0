<?php

namespace Drupal\labdoo_dootrip\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface;
use Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface;
use Drupal\queue_manager\Exception\EmptyQueueItemException;
use Drupal\queue_manager\Model\QueueDataModel;
use Drupal\queue_manager\Model\QueueDataModelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker that processes the dootrip capacity.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @QueueWorker(
 *   id = "labdoo_dootrip_calculate_capacity",
 *   title = @Translation("Calculate the dootrip capacity"),
 * )
 */
class DootripCapacityQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The dootrip compute service.
   *
   * @var \Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface
   */
  protected DootripComputeInterface $dootripCompute;

  /**
   * The dootrip repository.
   *
   * @var \Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface
   */
  protected DootripRepositoryInterface $dootripRepository;

  /**
   * {@inheritDoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    DootripComputeInterface $dootripCompute,
    DootripRepositoryInterface $dootripRepository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerChannelFactory->get('bb_valentina');
    $this->dootripCompute = $dootripCompute;
    $this->dootripRepository = $dootripRepository;
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
    /** @var \Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface $dootripCompute */
    $dootripCompute = $container->get('labdoo_dootrip.compute');
    /** @var \Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface $dootripRepository */
    $dootripRepository = $container->get('labdoo_dootrip.repository');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $loggerChannelFactory,
      $dootripCompute,
      $dootripRepository
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    try {
      $data = $this->checkData($data);
      $dootripId = $data->getData();
      if (is_array($dootripId)) {
        $dootripId = reset($dootripId);
      }
      if ($dootripId === NULL) {
        throw new \Exception('Invalid dootrip');
      }
      if ($dootripId instanceof EntityInterface) {
        $dootripId = $dootripId->id();
      }

      $dootrip = $this->dootripRepository->load($dootripId);
      if ($dootrip === NULL) {
        throw new \Exception('Invalid dootrip');
      }

      $this->dootripCompute->computeDootripCapacity($dootrip);
      $this->dootripRepository->saveEntity($dootrip);
    }
    catch (EmptyQueueItemException $exception) {
      $this->logger->warning($exception->getMessage());
    }
    catch (\Exception $exception) {
      $errorMessage = sprintf(
        'Error processing dootrip: %s',
        $exception->getMessage()
      );
      $this->logger->error($errorMessage);

      throw $exception;
    }
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
