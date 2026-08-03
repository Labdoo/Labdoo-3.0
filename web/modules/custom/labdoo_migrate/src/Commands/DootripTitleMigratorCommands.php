<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Commands to update dootrip titles from Drupal 7.
 */
class DootripTitleMigratorCommands extends DrushCommands {

  /**
   * DootripTitleMigratorCommands constructor.
   */
  public function __construct(
    protected Connection $database,
    protected ConnectionManagerInterface $externalConnectionManager,
    protected EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct();
  }

  /**
   * Updates empty or incorrectly formatted dootrip titles from Drupal 7.
   *
   * @command labdoo:migrate-update-dootrip-titles
   * @aliases lmudt
   * @usage drush labdoo:migrate-update-dootrip-titles
   *   Queries Drupal 10 for empty or incorrectly formatted dootrip titles,
   *   retrieves the correct titles from Drupal 7, and updates them in Drupal 10.
   */
  public function updateDootripTitles(): void {
    try {
      $externalConnection = $this->externalConnectionManager->setConnection();
    }
    catch (\Exception $e) {
      $this->io()->error('Error connecting to Drupal 7 database: ' . $e->getMessage());
      return;
    }

    try {
      // 1. Query Drupal 10 for dootrip nodes.
      $query = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid', 'title'])
        ->condition('type', 'dootrip');
      $results = $query->execute()->fetchAll();

      $nids_to_update = [];
      foreach ($results as $row) {
        $title = $row->title;
        // Check if title is empty or has an incorrect format (not starting with "Dootrip #" and 9 digits)
        if ($title === NULL || trim($title) === '' || !preg_match('/^Dootrip\s+#[0-9]{9}/i', $title)) {
          $nids_to_update[] = (int) $row->nid;
        }
      }

      if (empty($nids_to_update)) {
        $this->io()->success('All dootrip titles are already in the correct format. No updates needed.');
        $this->externalConnectionManager->restoreConnection();
        return;
      }

      $this->io()->comment(sprintf('Found %d dootrips with empty or incorrectly formatted titles in Drupal 10.', count($nids_to_update)));

      // 2. Query Drupal 7 for the correct titles of these nodes.
      $d7_titles = [];
      $chunks = array_chunk($nids_to_update, 500);
      foreach ($chunks as $chunk) {
        $d7_results = $externalConnection->select('node', 'n')
          ->fields('n', ['nid', 'title'])
          ->condition('nid', $chunk, 'IN')
          ->execute()
          ->fetchAllAssoc('nid', \PDO::FETCH_ASSOC);

        foreach ($d7_results as $nid => $row) {
          $d7_titles[$nid] = $row['title'];
        }
      }

      if (empty($d7_titles)) {
        $this->io()->warning('No corresponding titles found in Drupal 7 for the identified dootrips.');
        $this->externalConnectionManager->restoreConnection();
        return;
      }

      $this->io()->comment(sprintf('Retrieved %d matching titles from Drupal 7 database. Applying updates...', count($d7_titles)));

      // 3. Update the titles in Drupal 10.
      $count = 0;
      foreach ($d7_titles as $nid => $d7_title) {
        if ($d7_title === NULL || trim($d7_title) === '') {
          continue;
        }

        /** @var \Drupal\node\NodeInterface $node */
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
        if ($node) {
          $old_title = $node->getTitle() ?: '[Vacio]';
          $node->setTitle($d7_title);
          $node->save();
          $this->io()->writeln(dt('Updated NID @nid: "@old" -> "@new"', [
            '@nid' => $nid,
            '@old' => $old_title,
            '@new' => $d7_title,
          ]));
          $count++;
        }
      }

      // Invalidate cache tags to refresh node views/lists.
      if ($count > 0) {
        \Drupal::service('cache_tags.invalidator')->invalidateTags(['node_list']);
      }

      $this->io()->success(dt('Process completed successfully. Updated @count dootrip titles from Drupal 7.', [
        '@count' => $count,
      ]));
    }
    catch (\Exception $e) {
      $this->io()->error('An error occurred during execution: ' . $e->getMessage());
    }

    $this->externalConnectionManager->restoreConnection();
  }

}
