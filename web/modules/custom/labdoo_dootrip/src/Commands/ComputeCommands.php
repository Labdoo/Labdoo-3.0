<?php

namespace Drupal\labdoo_dootrip\Commands;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface;
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
   * The dootrip compute service.
   *
   * @var \Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface
   */
  protected DootripComputeInterface $dootripCompute;

  /**
   * ComputeCommands constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\labdoo_dootrip\Service\Queue\Feeder\QueueFeederInterface $queueFeeder
   *   The queue feeder.
   * @param \Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface $dootripCompute
   *   The dootrip compute service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    QueueFeederInterface $queueFeeder,
    DootripComputeInterface $dootripCompute
  ) {

    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->queueFeeder = $queueFeeder;
    $this->dootripCompute = $dootripCompute;
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

  /**
   * Regenerates the titles of dootrips that have an empty title or incorrect format.
   *
   * @command labdoo-regenerate-dootrip-titles
   * @aliases labdoo:reg-dootrip-titles
   * @usage labdoo-regenerate-dootrip-titles
   *   Regenerates the titles of dootrips that have an empty title or incorrect format.
   */
  public function regenerateDootripTitles(): void {
    $db = \Drupal::database();

    // 1. Fast SQL update for completely empty/null titles to be extremely efficient.
    $empty_count = 0;
    try {
      $empty_count = (int) $db->select('node_field_data', 'n')
        ->condition('type', 'dootrip')
        ->condition('title', ['', NULL], 'IN')
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($empty_count > 0) {
        $db->query("UPDATE {node_field_data} SET title = CONCAT('Dootrip #', LPAD(nid, 9, '0')) WHERE type = 'dootrip' AND (title = '' OR title IS NULL)");
        $db->query("UPDATE {node_field_revision} r JOIN {node_field_data} d ON r.nid = d.nid SET r.title = d.title WHERE d.type = 'dootrip' AND (r.title = '' OR r.title IS NULL)");
        $this->io()->writeln(dt('Updated @count empty titles via fast SQL.', ['@count' => $empty_count]));
      }
    }
    catch (\Exception $e) {
      $this->io()->error('SQL update failed: ' . $e->getMessage());
    }

    // 2. Load and process only those dootrips that are truly malformed (not matching ^Dootrip\s+#[0-9]{9}).
    $count = 0;
    try {
      $query = $db->select('node_field_data', 'n')
        ->fields('n', ['nid', 'title'])
        ->condition('type', 'dootrip');
      $results = $query->execute()->fetchAll();

      $malformed_nids = [];
      foreach ($results as $row) {
        $title = $row->title;
        if ($title === NULL || trim($title) === '' || !preg_match('/^Dootrip\s+#[0-9]{9}/i', $title)) {
          $malformed_nids[] = (int) $row->nid;
        }
      }

      if (!empty($malformed_nids)) {
        $dootrips = $this->entityTypeManager
          ->getStorage('node')
          ->loadMultiple($malformed_nids);

        foreach ($dootrips as $dootrip) {
          $title = $dootrip->getTitle();
          $old_title = $title ?: '[Vacio]';
          $this->dootripCompute->setDootripTitle($dootrip);
          $dootrip->save();
          $new_title = $dootrip->getTitle();
          $this->io()->writeln(dt('Regenerated dootrip NID @nid: "@old" -> "@new"', [
            '@nid' => $dootrip->id(),
            '@old' => $old_title,
            '@new' => $new_title,
          ]));
          $count++;
        }
      }
    }
    catch (\Exception $e) {
      $this->io()->error('Error processing malformed dootrips: ' . $e->getMessage());
      return;
    }

    // Rebuild cache tags to make sure updated nodes are shown correctly.
    if ($empty_count > 0 || $count > 0) {
      \Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list']);
    }

    // 3. Enqueue regenerated dootrips (and any others lacking localization) for geocoding.
    $geocoded_count = 0;
    try {
      $query = $db->select('node_field_data', 'n')
        ->fields('n', ['nid'])
        ->condition('type', 'dootrip');
      $query->condition('title', 'Dootrip #%', 'LIKE');
      $query->condition('title', '% - from %', 'NOT LIKE');
      $nids_to_geocode = $query->execute()->fetchCol();

      if (!empty($nids_to_geocode)) {
        /** @var \Drupal\Core\Queue\QueueFactory $queueFactory */
        $queueFactory = \Drupal::service('queue');
        $queue = $queueFactory->get('labdoo_dootrip_geocoding');

        // Delete existing queue items of this queue to avoid duplicate processing.
        $queue->deleteQueue();

        foreach ($nids_to_geocode as $nid) {
          $queue->createItem(['nid' => (int) $nid]);
        }
        $geocoded_count = count($nids_to_geocode);
        $this->io()->writeln(dt('Enqueued @count dootrips for geocoding to restore/compute their localizations.', ['@count' => $geocoded_count]));
      }
    }
    catch (\Exception $e) {
      $this->io()->error('Failed to enqueue dootrips for geocoding: ' . $e->getMessage());
    }

    $total = $empty_count + $count + $geocoded_count;
    $this->io()->success(dt('Process completed. Regenerated @total dootrip titles (@empty empty, @incorrect incorrect). Enqueued @geocoded dootrips for geocoding.', [
      '@total' => $total,
      '@empty' => $empty_count,
      '@incorrect' => $count + $geocoded_count,
      '@geocoded' => $geocoded_count,
    ]));
  }

  /**
   * Checks if a dootrip node has valid coordinates.
   *
   * @param \Drupal\node\NodeInterface $dootrip
   *   The dootrip node.
   *
   * @return bool
   *   True if coordinates are present and non-zero, false otherwise.
   */
  private function hasCoordinates($dootrip): bool {
    if ($dootrip->hasField('field_origin_of_the_trip') && !$dootrip->get('field_origin_of_the_trip')->isEmpty()) {
      $originData = $dootrip->get('field_origin_of_the_trip')->first()->getValue();
      if (isset($originData['lat']) && isset($originData['lon'])) {
        if (abs((float)$originData['lat']) > 0.1 || abs((float)$originData['lon']) > 0.1) {
          return TRUE;
        }
      }
    }
    if ($dootrip->hasField('field_destination_of_the_trip') && !$dootrip->get('field_destination_of_the_trip')->isEmpty()) {
      $destinationData = $dootrip->get('field_destination_of_the_trip')->first()->getValue();
      if (isset($destinationData['lat']) && isset($destinationData['lon'])) {
        if (abs((float)$destinationData['lat']) > 0.1 || abs((float)$destinationData['lon']) > 0.1) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
