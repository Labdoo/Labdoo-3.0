<?php

namespace Drupal\labdoo_dootronics\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\labdoo_dootronics\Service\Compute\DootronicComputeInterface;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Drupal\queue_manager\Exception\EmptyQueueItemException;
use Drupal\queue_manager\Model\QueueDataModel;
use Drupal\queue_manager\Model\QueueDataModelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * {@inheritDoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    DootronicComputeInterface $dootronicCompute,
    DootronicRepositoryInterface $dootronicRepository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerChannelFactory->get('labdoo_dootronics');
    $this->dootronicCompute = $dootronicCompute;
    $this->dootronicRepository = $dootronicRepository;
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

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $loggerChannelFactory,
      $dootronicCompute,
      $dootronicRepository
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    try {
      $data = $this->checkData($data);
      $dootronicId = $data->getData();
      if (is_array($dootronicId)) {
        $dootronicId = reset($dootronicId);
      }
      if ($dootronicId === NULL) {
        throw new \Exception('Invalid dootronic');
      }
      if ($dootronicId instanceof EntityInterface) {
        $dootronicId = $dootronicId->id();
      }

      $dootronic = $this->dootronicRepository->load((int) $dootronicId);
      if ($dootronic === NULL) {
        throw new \Exception('Invalid dootronic');
      }

      $this->dootronicCompute->computeEdooVillageData($dootronic);
      $this->dootronicCompute->computeHubData($dootronic);
      $this->dootronicCompute->computeRelatedDootrips($dootronic);
      $this->dootronicRepository->saveEntity($dootronic);
    }
    catch (EmptyQueueItemException $exception) {
      $this->logger->warning($exception->getMessage());
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
