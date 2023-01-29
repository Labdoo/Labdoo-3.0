<?php

namespace Drupal\labdoo_dootrip\Commands;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_dootrip\Service\Queue\Feeder\QueueFeederInterface;
use Drush\Commands\DrushCommands;

/**
 * Dootrip compute commands.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ComputeCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The queue feeder.
   *
   * @var \Drupal\labdoo_dootrip\Service\Queue\Feeder\QueueFeederInterface
   */
  protected QueueFeederInterface $queueFeeder;

  /**
   * ComputeCommands constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\labdoo_dootrip\Service\Queue\Feeder\QueueFeederInterface $queueFeeder
   *   The queue feeder.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    QueueFeederInterface $queueFeeder
  ) {

    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->queueFeeder = $queueFeeder;
  }

  /**
   * Enqueues all dootrips to be computed.
   *
   * @command labdoo-enqueue-dootrips
   * @aliases labdoo:edootrips
   * @usage labdoo-enqueue-dootrips
   *   Enqueues all dootrips to be computed.
   */
  public function enqueueDootrips(): void {
    try {
      $dootrips = $this->entityTypeManager
        ->getStorage('node')
        ->loadByProperties(['type' => 'dootrip']);
    }
    catch (
      InvalidPluginDefinitionException
      | PluginNotFoundException $e
    ) {
      die('Error loading the dootrips');
    }

    foreach ($dootrips as $dootrip) {
      $this->queueFeeder->feedQueue($dootrip);
    }
  }

}
