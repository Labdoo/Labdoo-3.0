<?php

namespace Drupal\labdoo_dootronics\Commands;

use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;
use Drupal\labdoo_dootronics\Service\Queue\Feeder\QueueFeederInterface;
use Drush\Commands\DrushCommands;

/**
 * Dootronic compute command.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ComputeCommands extends DrushCommands {

  /**
   * The start time.
   *
   * @var mixed
   */
  private $startTime;

  /**
   * The nids.
   *
   * @var mixed
   */
  private $nids;

  /**
   * The dry-run mode.
   *
   * @var mixed
   */
  private $dryRun;

  /**
   * The Dootronic repository.
   *
   * @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface
   */
  protected DootronicRepositoryInterface $dootronicRepository;

  /**
   * The recompute queue feeder.
   *
   * @var \Drupal\labdoo_dootronics\Service\Queue\Feeder\QueueFeederInterface
   */
  protected QueueFeederInterface $queueFeeder;

  /**
   * The total entities.
   *
   * @var int
   */
  protected int $total;

  /**
   * ComputeCommands constructor.
   *
   * @param \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository
   *   The Dootronic repository.
   * @param \Drupal\labdoo_dootronics\Service\Queue\Feeder\QueueFeederInterface $queueFeeder
   *   The queue feeder.
   */
  public function __construct(
    DootronicRepositoryInterface $dootronicRepository,
    QueueFeederInterface $queueFeeder
  ) {
    parent::__construct();
    $this->dootronicRepository = $dootronicRepository;
    $this->queueFeeder = $queueFeeder;
  }

  /**
   * Computes the Dootronic calculations.
   *
   * @param array $options
   *   Command options.
   *
   * @command labdoo-dootronic-compute [--nids=123,456,789] [--dry-run]
   * @aliases labdoo-dc
   * @usage labdoo-dootronic-compute
   *   Computes the Dootronic calculations.
   *
   * @option nids List of IDs to compute.
   * @option dry-run Whether to run this command in dry-run mode. Specify this parameter to activate the dry-run mode.
   */
  public function recalculate(
    array $options = [
      'nids' => NULL,
      'dry-run' => FALSE,
    ]
  ): void {
    try {
      $this->setEnvironment($options);
      $sourceEntities = $this->getEntities();
      $queuedEntities = $this->enqueueEntities($sourceEntities);
      $this->tearDown($queuedEntities);
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }
  }

  /**
   * Sets the environment.
   *
   * @param array $options
   *   Command options.
   *
   * @throws \Exception
   */
  protected function setEnvironment(
    array $options
  ): void {
    $this->logger->notice('Setting the environment...');
    $this->startTime = microtime(TRUE);
    if ($options['nids'] !== NULL) {
      $this->nids = explode(',', $options['nids']);
    }
    $this->dryRun = $options['dry-run'];
  }

  /**
   * Retrieves the entities.
   *
   * @return array
   *   Returns an array of entities.
   *
   * @throws \Exception
   */
  protected function getEntities(): array {
    $this->logger->notice('Retrieving the entities...');
    if (!empty($this->nids)) {
      $entities = $this->dootronicRepository->loadByIds($this->nids);
    }
    else {
      $entities = $this->dootronicRepository
        ->loadByProperties([
          'type' => 'dootronic',
        ]);
    }

    $this->total = count($entities);
    $message = sprintf(
      '%d entities found.',
      $this->total
    );
    $this->logger->notice($message);

    return $entities;
  }

  /**
   * Enqueues the entities.
   *
   * @param array $entities
   *   The entities.
   *
   * @return int
   *   Returns the number of enqueued entities.
   *
   * @throws \Exception
   */
  protected function enqueueEntities(
    array $entities
  ): int {
    $this->logger->notice('Enqueuing the entities...');
    $i = 0;
    $queuedEntities = 0;

    foreach ($entities as $entity) {
      ++$i;

      $infoMessage = sprintf(
        '[%s] [%d/%d] Enqueued %d',
        date('d/m/Y H:i:s'),
        $i,
        $this->total,
        $entity->id()
      );

      if ($this->dryRun === TRUE) {
        $this->logger->notice($infoMessage);
        ++$queuedEntities;

        continue;
      }

      $this->queueFeeder->feedQueue($entity);
      $this->logger->notice($infoMessage);
      ++$queuedEntities;
    }

    return $queuedEntities;
  }

  /**
   * Finishes the process.
   *
   * @param int $queued
   *   The number of enqueued entities.
   */
  protected function tearDown(int $queued): void {
    $timeElapsedSeconds = microtime(TRUE) - $this->startTime;
    $infoMessage = sprintf(
      "\n\nPROCESS FINISHED:\n"
      . "-- Time elapsed: %s.\n"
      . "-- %d/%d entities enqueued.\n",
      gmdate("H:i:s", $timeElapsedSeconds),
      $queued,
      $this->total
    );
    $this->logger->notice($infoMessage);
  }

}
