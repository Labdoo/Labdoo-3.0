<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Node deletion commands.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class NodeDeleteCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The progress bar.
   *
   * @var \Symfony\Component\Console\Helper\ProgressBar
   */
  private ProgressBar $progressBar;

  /**
   * NodeDeleteCommands constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Deletes all nodes of a specific bundle.
   *
   * @param string $bundle
   *   The bundle (content type) to delete.
   * @param array $options
   *   Command options.
   *
   * @command labdoo-delete-nodes
   * @aliases labdoo-delete
   * @usage labdoo-delete-nodes article
   *   Deletes all nodes of the "article" content type.
   *
   * @option limit Limits the execution to the given number of nodes.
   * @option dry-run Whether to run this command in dry-run mode. Specify this parameter to activate the dry-run mode.
   */
  public function deleteNodes(
    string $bundle,
    array $options = [
      'limit' => -1,
      'dry-run' => FALSE,
    ]
  ): void {
    try {
      $startTime = microtime(TRUE);
      $this->logger->notice(sprintf('Starting deletion of nodes with bundle "%s"...', $bundle));

      // Get the node storage
      $nodeStorage = $this->entityTypeManager->getStorage('node');

      // Query for nodes of the specified bundle
      $query = $nodeStorage->getQuery()
        ->condition('type', $bundle)
        ->accessCheck(FALSE);

      // Apply limit if specified
      if ($options['limit'] > 0) {
        $query->range(0, $options['limit']);
      }

      // Get the node IDs
      $nids = $query->execute();
      $count = count($nids);

      if ($count === 0) {
        $this->logger->notice(sprintf('No nodes found with bundle "%s".', $bundle));
        return;
      }

      $this->logger->notice(sprintf('Found %d nodes of type "%s" to delete.', $count, $bundle));

      // If dry-run, just report what would be deleted
      if ($options['dry-run']) {
        $this->logger->notice('Dry run mode: No nodes will be deleted.');
        return;
      }

      // Initialize progress bar
      $this->initProgressBar($count, sprintf('Deleting nodes of type "%s"', $bundle));

      // Delete the nodes in chunks to avoid memory issues
      $chunks = array_chunk($nids, 50);
      $deleted = 0;

      foreach ($chunks as $chunk) {
        $entities = $nodeStorage->loadMultiple($chunk);
        $nodeStorage->delete($entities);
        $deleted += count($chunk);

        // Update progress bar for each node in the chunk
        for ($i = 0; $i < count($chunk); $i++) {
          $this->advanceProgressBar();
        }
      }

      // Finish the progress bar
      if ($this->progressBar) {
        $this->progressBar->finish();
        $this->output->writeln('');
      }

      $timeElapsedSeconds = microtime(TRUE) - $startTime;
      $this->logger->notice(sprintf(
        "\n\nDELETION COMPLETED:\n" .
        "-- Bundle: %s.\n" .
        "-- Time elapsed: %s.\n" .
        "-- %d nodes deleted.",
        $bundle,
        gmdate("H:i:s", $timeElapsedSeconds),
        $deleted
      ));
    }
    catch (\Exception $e) {
      $this->logger->error($e->getMessage());
    }
  }

  /**
   * Initializes a progress bar.
   *
   * @param int $count
   *   The number of items to process.
   * @param string $message
   *   The message to display.
   */
  protected function initProgressBar(int $count, string $message): void {
    $this->progressBar = new ProgressBar($this->output, $count);
    $this->progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s% %message%');
    $this->progressBar->setMessage($message);
    $this->progressBar->start();
  }

  /**
   * Advances the progress bar.
   */
  protected function advanceProgressBar(): void {
    $this->progressBar?->advance();
  }

}
