<?php

namespace Drupal\labdoo_global_action\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_global_action\Service\Repository\GlobalActionRepository;
use Drush\Commands\DrushCommands;

/**
 * Global action Drush commands.
 */
class GlobalActionCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The global action repository.
   *
   * @var \Drupal\labdoo_global_action\Service\Repository\GlobalActionRepository
   */
  protected GlobalActionRepository $globalActionRepository;

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    GlobalActionRepository $globalActionRepository,
    Connection $database
  ) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->globalActionRepository = $globalActionRepository;
    $this->database = $database;
  }

  /**
   * Rebuilds all action nodes from source node types.
   *
   * @command labdoo:actions-rebuild
   * @aliases l-actions-rebuild
   * @option batch-size Number of nodes to process per batch.
   */
  public function rebuildActions(array $options = ['batch-size' => 200]): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $batchSize = max(1, (int) ($options['batch-size'] ?? 200));
    $geocoderConfig = \Drupal::configFactory()->getEditable('geocoder.settings');
    $originalGeocoderPresaveDisabled = (bool) $geocoderConfig->get('geocoder_presave_disabled');

    if ($originalGeocoderPresaveDisabled === FALSE) {
      $this->output()->writeln('Temporarily disabling geocoder presave during rebuild...');
      $geocoderConfig->set('geocoder_presave_disabled', TRUE)->save();
    }

    try {
      $sourceNodeIds = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', ['dootronic', 'dootrip', 'edoovillage', 'hub'], 'IN')
        ->sort('created', 'ASC')
        ->execute();

      $actionNodeIds = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'action')
        ->execute();

      if (!empty($actionNodeIds)) {
        $this->output()->writeln(sprintf('Deleting existing action nodes (%d)...', count($actionNodeIds)));
        $this->io()->progressStart(count($actionNodeIds));

        foreach (array_chunk($actionNodeIds, $batchSize) as $actionNodeIdBatch) {
          $actionNodes = $storage->loadMultiple($actionNodeIdBatch);
          foreach ($actionNodeIdBatch as $actionNodeId) {
            if (isset($actionNodes[$actionNodeId])) {
              $actionNode = $actionNodes[$actionNodeId];
              $actionNode->delete();
            }
            $this->io()->progressAdvance();
          }
        }

        $this->io()->progressFinish();
      }

      if (empty($sourceNodeIds)) {
        $this->output()->writeln('No source nodes found to rebuild actions.');
        return;
      }

      $this->output()->writeln(sprintf('Rebuilding actions from source nodes (%d)...', count($sourceNodeIds)));
      $this->io()->progressStart(count($sourceNodeIds));

      foreach (array_chunk($sourceNodeIds, $batchSize) as $sourceNodeIdBatch) {
        $sourceNodes = $storage->loadMultiple($sourceNodeIdBatch);
        foreach ($sourceNodeIdBatch as $sourceNodeId) {
          if (isset($sourceNodes[$sourceNodeId])) {
            $sourceNode = $sourceNodes[$sourceNodeId];
            $this->globalActionRepository->createGlobalAction($sourceNode);
          }
          $this->io()->progressAdvance();
        }
      }

      $this->io()->progressFinish();

      $createdActions = (int) $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'action')
        ->count()
        ->execute();

      $this->output()->writeln(sprintf(
        'Actions rebuilt. Deleted: %d, Created: %d',
        count($actionNodeIds),
        $createdActions
      ));
    }
    finally {
      if ($originalGeocoderPresaveDisabled === FALSE) {
        $geocoderConfig->set('geocoder_presave_disabled', FALSE)->save();
        $this->output()->writeln('Geocoder presave setting restored.');
      }
    }
  }

  /**
   * Deletes action data directly in DB tables using SQL DELETE.
   *
   * @command labdoo:actions-delete-sql
   * @aliases l-actions-delete-sql
   */
  public function deleteActionsSql(): void {
    $nids = $this->database->select('node_field_data', 'nfd')
      ->fields('nfd', ['nid'])
      ->condition('type', 'action')
      ->execute()
      ->fetchCol();

    if (empty($nids)) {
      $this->output()->writeln('No action nodes found to delete.');
      return;
    }

    $nids = array_map('intval', $nids);

    $this->output()->writeln(sprintf('Deleting action data with SQL from %d nodes...', count($nids)));

    $vids = $this->database->select('node_revision', 'nr')
      ->fields('nr', ['vid'])
      ->condition('nid', $nids, 'IN')
      ->execute()
      ->fetchCol();
    $vids = array_map('intval', $vids);

    $fieldTables = $this->database->schema()->findTables('node\_\_%');
    $fieldRevisionTables = $this->database->schema()->findTables('node\_revision\_\_%');

    foreach ($fieldTables as $table) {
      $this->database->delete($table)
        ->condition('bundle', 'action')
        ->execute();
    }

    foreach ($fieldRevisionTables as $table) {
      $this->database->delete($table)
        ->condition('bundle', 'action')
        ->execute();
    }

    if (!empty($vids)) {
      $this->database->delete('node_field_revision')
        ->condition('vid', $vids, 'IN')
        ->execute();

      $this->database->delete('node_revision')
        ->condition('vid', $vids, 'IN')
        ->execute();
    }

    $this->database->delete('node_field_data')
      ->condition('nid', $nids, 'IN')
      ->execute();

    $this->database->delete('node_field_revision')
      ->condition('nid', $nids, 'IN')
      ->execute();

    $this->database->delete('node')
      ->condition('nid', $nids, 'IN')
      ->execute();

    $this->output()->writeln(sprintf('Action SQL delete completed. Deleted nodes: %d', count($nids)));
  }

}
