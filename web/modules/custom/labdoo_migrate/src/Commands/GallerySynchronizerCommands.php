<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drupal\labdoo_migrate\Services\Media\FileManagerInterface;
use Drupal\labdoo_migrate\Traits\TextFormatMapperTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Content synchronization commands for Labdoo Galleries.
 *
 * This command synchronizes gallery content from Drupal 7 to Drupal 10.
 * It migrates:
 * - node_gallery_gallery content types to gallery content types
 * - node_gallery_item content types to media entities referenced by gallery
 * - Preserves the original node IDs
 * - Maps fields between source and destination
 *
 * Usage:
 * drush labdoo-synchronize-galleries
 * drush labdoo-sync-galleries --nids=123,456,789 --limit=10 --dry-run
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class GallerySynchronizerCommands extends DrushCommands {

  use TextFormatMapperTrait;

  private const GALLERY_CONTENT_TYPE = 'gallery';
  private const SOURCE_GALLERY_CONTENT_TYPE = 'node_gallery_gallery';
  private const SOURCE_GALLERY_ITEM_CONTENT_TYPE = 'node_gallery_item';

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
   * The limit.
   *
   * @var mixed
   */
  private $limit;

  /**
   * The dry-run mode.
   *
   * @var mixed
   */
  private $dryRun;

  /**
   * The progress bar.
   *
   * @var \Symfony\Component\Console\Helper\ProgressBar
   */
  private $progressBar;

  /**
   * GallerySynchronizerCommands constructor.
   */
  public function __construct(
    protected ConnectionManagerInterface $externalConnectionManager,
    protected FileManagerInterface $fileManager,
    protected EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct();
  }

  /**
   * Synchronizes Labdoo Galleries taking a Drupal 7 instance as a source.
   *
   * @param array $options
   *   Command options.
   *
   * @command labdoo-synchronize-galleries [nids=123,456,789] [limit=9] [dry-run]
   * @aliases labdoo-sync-galleries
   * @usage labdoo-synchronize-galleries
   *   Synchronizes the galleries from Drupal 7 to Drupal 10.
   *
   * @option nids List of Drupal 7 gallery IDs to synchronize.
   * @option limit Limits the execution to the given elements.
   * @option dry-run Whether to run this command in dry-run mode. Specify this parameter to activate the dry-run mode.
   */
  public function startSync(
    array $options = [
      'nids' => NULL,
      'limit' => -1,
      'dry-run' => FALSE,
    ]
  ) {
    try {
      $this->setEnvironment($options);
      $this->logger->notice('Starting gallery synchronization process...');

      // Start measuring memory usage
      $initialMemory = memory_get_usage();

      // Disable entity storage caches
      $this->disableEntityStorageCache();

      // Migrate galleries
      $this->logger->notice('Step 1: Retrieving and migrating galleries');
      $sourceGalleries = $this->getSourceGalleries();

      // Initialize counters
      $totalCreated = 0;
      $totalUpdated = 0;
      $totalSkipped = 0;

      // Initialize progress bar
      $this->initProgressBar(count($sourceGalleries), 'Processing galleries');

      // Process each gallery
      foreach ($sourceGalleries as $sourceGallery) {
        // Create or update gallery
        $galleryResult = $this->updateDestinationGallery($sourceGallery);

        // Update counters
        $totalCreated += $galleryResult['created'];
        $totalUpdated += $galleryResult['updated'];
        $totalSkipped += $galleryResult['skipped'];

        // If gallery was created or updated successfully
        if ($galleryResult['success']) {
          // Get gallery items for this gallery
          $sourceGalleryItems = $this->getSourceGalleryItems($sourceGallery['nid']);

          // Process gallery items
          if (!empty($sourceGalleryItems)) {
            $galleryItemResult = $this->updateDestinationGalleryItems($sourceGalleryItems, $galleryResult['entity']);

            // Update counters
            $totalCreated += $galleryItemResult['created'];
            $totalUpdated += $galleryItemResult['updated'];
            $totalSkipped += $galleryItemResult['skipped'];
          }
        }

        // Free memory
        $this->externalConnectionManager->restoreConnection();
        gc_collect_cycles();
      }

      // Re-enable entity storage caches
      $this->enableEntityStorageCache();

      // Log memory usage
      $peakMemory = memory_get_peak_usage();
      $memoryUsed = $peakMemory - $initialMemory;
      $this->logger->notice(sprintf(
        'Memory usage: %s MB (peak: %s MB)',
        round($memoryUsed / 1048576, 2),
        round($peakMemory / 1048576, 2)
      ));

      $this->tearDown($totalCreated, $totalUpdated, $totalSkipped);
    }
    catch (\Exception $e) {
      $this->logger->error('Error during synchronization: ' . $e->getMessage());
      if (isset($e->getTrace()[0])) {
        $this->logger->error('Error location: ' . ($e->getTrace()[0]['file'] ?? 'unknown') . 
          ' line ' . ($e->getTrace()[0]['line'] ?? 'unknown'));
      }
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
  protected function setEnvironment(array $options): void {
    $this->logger->notice('Setting the environment...');
    $this->startTime = microtime(TRUE);
    if ($options['nids'] !== NULL) {
      $this->nids = explode(',', $options['nids']);
    }
    $this->limit = $options['limit'];
    $this->dryRun = $options['dry-run'];
  }

  /**
   * Retrieves the source galleries from Drupal 7.
   *
   * @return array
   *   Returns an array of source galleries.
   *
   * @throws \Exception
   */
  protected function getSourceGalleries(): array {
    $this->logger->notice('Retrieving the source galleries...');

    $galleriesResult = [];

    // Establish connection once
    $connection = $this->externalConnectionManager->setConnection();

    // Build query with LEFT JOINs to get all data in one query
    $query = $connection->select('node', 'n');
    $query->fields('n', ['nid', 'title', 'uid', 'status', 'created', 'changed']);

    // Join body field
    $query->leftJoin('field_data_body', 'fdb', 'n.nid = fdb.entity_id AND fdb.entity_type = :entity_type AND fdb.bundle = :bundle', 
      [':entity_type' => 'node', ':bundle' => self::SOURCE_GALLERY_CONTENT_TYPE]);
    $query->fields('fdb', ['body_value', 'body_summary', 'body_format']);

    // Join edoovillage reference
    $query->leftJoin('field_data_field_photo_album_edoovillage', 'fpe', 'n.nid = fpe.entity_id AND fpe.entity_type = :entity_type AND fpe.bundle = :bundle',
      [':entity_type' => 'node', ':bundle' => self::SOURCE_GALLERY_CONTENT_TYPE]);
    $query->fields('fpe', ['field_photo_album_edoovillage_target_id']);

    // Join hub reference
    $query->leftJoin('field_data_field_photo_album_hub', 'fph', 'n.nid = fph.entity_id AND fph.entity_type = :entity_type AND fph.bundle = :bundle',
      [':entity_type' => 'node', ':bundle' => self::SOURCE_GALLERY_CONTENT_TYPE]);
    $query->fields('fph', ['field_photo_album_hub_target_id']);

    // Add conditions
    $query->condition('n.type', self::SOURCE_GALLERY_CONTENT_TYPE);

    if ($this->nids !== NULL) {
      $query->condition('n.nid', $this->nids, 'IN');
    }

    if ($this->limit > -1) {
      $query->range(0, $this->limit);
    }

    // Execute query
    $galleries = $query->execute()->fetchAll();

    foreach ($galleries as $gallery) {
      $galleriesResult[] = [
        'nid' => $gallery->nid,
        'title' => $gallery->title,
        'uid' => $gallery->uid,
        'status' => $gallery->status,
        'created' => $gallery->created,
        'changed' => $gallery->changed,
        'body' => isset($gallery->body_value) ? [
          'value' => $gallery->body_value,
          'summary' => $gallery->body_summary,
          'format' => $gallery->body_format,
        ] : NULL,
        'edoovillage' => $gallery->field_photo_album_edoovillage_target_id,
        'hub' => $gallery->field_photo_album_hub_target_id,
      ];
    }

    $message = sprintf(
      '%d source galleries found.',
      count($galleriesResult)
    );
    $this->logger->notice($message);

    return $galleriesResult;
  }

  /**
   * Retrieves the source gallery items from Drupal 7.
   *
   * @param int|null $galleryId
   *   The gallery ID to filter by. If NULL, all gallery items are returned.
   *
   * @return array
   *   Returns an array of source gallery items.
   *
   * @throws \Exception
   */
  protected function getSourceGalleryItems(?int $galleryId = NULL): array {
    if ($galleryId) {
      $this->logger->notice(sprintf('Retrieving gallery items for gallery ID %d...', $galleryId));
    } else {
      $this->logger->notice('Retrieving all source gallery items...');
    }

    $galleryItemsResult = [];

    // Establish connection once
    $connection = $this->externalConnectionManager->setConnection();

    // Build query with LEFT JOINs to get all data in one query
    $query = $connection->select('node', 'n');
    $query->fields('n', ['nid', 'title', 'uid', 'status', 'created', 'changed']);

    // Join body field
    $query->leftJoin('field_data_body', 'fdb', 'n.nid = fdb.entity_id AND fdb.entity_type = :entity_type AND fdb.bundle = :bundle', 
      [':entity_type' => 'node', ':bundle' => self::SOURCE_GALLERY_ITEM_CONTENT_TYPE]);
    $query->fields('fdb', ['body_value', 'body_summary', 'body_format']);

    // Join gallery reference from node_gallery_relationship table
    $query->leftJoin('node_gallery_relationship', 'ngr', 'n.nid = ngr.nid');
    $query->fields('ngr', ['ngid']);

    // Join media file reference
    $query->leftJoin('field_data_node_gallery_media', 'fgm', 'n.nid = fgm.entity_id AND fgm.entity_type = :entity_type AND fgm.bundle = :bundle',
      [':entity_type' => 'node', ':bundle' => self::SOURCE_GALLERY_ITEM_CONTENT_TYPE]);
    $query->fields('fgm', ['node_gallery_media_fid']);

    // Add conditions
    $query->condition('n.type', self::SOURCE_GALLERY_ITEM_CONTENT_TYPE);

    // Filter by gallery ID if provided
    if ($galleryId) {
      $query->condition('ngr.ngid', $galleryId);
    }

    if ($this->limit > -1) {
      $query->range(0, $this->limit);
    }

    // Execute query
    $galleryItems = $query->execute()->fetchAll();

    // Get all file IDs to fetch in a single query
    $fileIds = [];
    foreach ($galleryItems as $galleryItem) {
      if (!empty($galleryItem->node_gallery_media_fid)) {
        $fileIds[] = $galleryItem->node_gallery_media_fid;
      }
    }

    // Fetch all files in a single query
    $files = [];
    if (!empty($fileIds)) {
      $fileQuery = $connection->select('file_managed', 'fm')
        ->fields('fm', ['fid', 'uri', 'filename'])
        ->condition('fid', $fileIds, 'IN');
      $fileResults = $fileQuery->execute()->fetchAll();

      foreach ($fileResults as $file) {
        $files[$file->fid] = $file;
      }
    }

    // Process gallery items
    foreach ($galleryItems as $galleryItem) {
      $mediaFile = NULL;

      if (!empty($galleryItem->node_gallery_media_fid) && isset($files[$galleryItem->node_gallery_media_fid])) {
        $file = $files[$galleryItem->node_gallery_media_fid];

        $mediaFile = [
          'fid' => $galleryItem->node_gallery_media_fid,
          'alt' => $galleryItem->title,
          'title' => $galleryItem->title,
          'uri' => $file->uri,
          'name' => $file->filename,
          'content' => $this->fileManager->getFileContents($file->uri, TRUE, TRUE),
        ];
      }

      $galleryItemsResult[] = [
        'nid' => $galleryItem->nid,
        'title' => $galleryItem->title,
        'uid' => $galleryItem->uid,
        'status' => $galleryItem->status,
        'created' => $galleryItem->created,
        'changed' => $galleryItem->changed,
        'body' => isset($galleryItem->body_value) ? [
          'value' => $galleryItem->body_value,
          'summary' => $galleryItem->body_summary,
          'format' => $galleryItem->body_format,
        ] : NULL,
        'gallery_ref' => $galleryItem->ngid,
        'media_file' => $mediaFile,
      ];
    }

    $message = sprintf(
      '%d source gallery items found.',
      count($galleryItemsResult)
    );
    $this->logger->notice($message);

    return $galleryItemsResult;
  }

  /**
   * Updates a single destination gallery with the source values.
   *
   * @param array $sourceGallery
   *   The source gallery.
   *
   * @return array
   *   Returns the result of the operation including created/updated count and the entity.
   *
   * @throws \Exception
   */
  protected function updateDestinationGallery(array $sourceGallery): array {
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $success = FALSE;
    $entity = NULL;

    try {
      // Check if node exists and is of correct type
      $nid = $sourceGallery['nid'];
      $existingNode = $this->entityTypeManager
        ->getStorage('node')
        ->load($nid);

      // If a node with this ID exists but is not a gallery, skip it
      if ($existingNode && $existingNode->bundle() !== self::GALLERY_CONTENT_TYPE) {
        $this->logger->warning(sprintf(
          'Skipping gallery %s (ID: %d): A node with this ID already exists but is not a gallery (type: %s)',
          $sourceGallery['title'],
          $nid,
          $existingNode->bundle()
        ));
        ++$skipped;
        return [
          'created' => $created,
          'updated' => $updated,
          'skipped' => $skipped,
          'success' => $success,
          'entity' => $entity,
        ];
      }

      // Create or update entity
      if (!$existingNode || $existingNode->bundle() !== self::GALLERY_CONTENT_TYPE) {
        // Create a new gallery with the original node ID
        $destinationEntity = $this->entityTypeManager
          ->getStorage('node')
          ->create([
            'type' => self::GALLERY_CONTENT_TYPE,
            'nid' => $nid,
          ]);
        ++$created;
      }
      else {
        // Update existing gallery
        $destinationEntity = $existingNode;
        ++$updated;
      }

      // Set basic fields
      $destinationEntity->set('title', $sourceGallery['title']);
      $destinationEntity->set('uid', $sourceGallery['uid']);
      $destinationEntity->set('status', $sourceGallery['status']);
      $destinationEntity->set('created', $sourceGallery['created']);
      $destinationEntity->set('changed', $sourceGallery['changed']);

      // Set description field
      if (!empty($sourceGallery['body'])) {
        $destinationEntity->set('body', [
          'value' => $sourceGallery['body']['value'],
          'summary' => $sourceGallery['body']['summary'],
          'format' => $this->mapFormat($sourceGallery['body']['format']),
        ]);
      }

      // Set parent field (edoovillage or hub)
      $parent = NULL;
      if (!empty($sourceGallery['edoovillage'])) {
        $parent = $sourceGallery['edoovillage'];
      }
      elseif (!empty($sourceGallery['hub'])) {
        $parent = $sourceGallery['hub'];
      }

      if ($parent) {
        $destinationEntity->set('field_parent', $parent);
      }

      // Save the entity
      if (!$this->dryRun) {
        $destinationEntity->save();
        $this->advanceProgressBar();
        $success = TRUE;
        $entity = $destinationEntity;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error saving gallery: ' . $e->getMessage());
      $success = FALSE;
    }

    return [
      'created' => $created,
      'updated' => $updated,
      'skipped' => $skipped,
      'success' => $success,
      'entity' => $entity,
    ];
  }

  /**
   * Updates the destination galleries with the source values.
   *
   * @param array $sourceGalleries
   *   The source galleries.
   *
   * @return array
   *   Returns the number of created/updated entities.
   *
   * @throws \Exception
   */
  protected function updateDestinationGalleries(array $sourceGalleries): array {
    $this->logger->notice('Creating/Updating the destination galleries...');

    $created = 0;
    $updated = 0;
    $skipped = 0;

    // Initialize progress bar
    $this->initProgressBar(count($sourceGalleries), 'Processing galleries');

    foreach ($sourceGalleries as $sourceGallery) {
      $result = $this->updateDestinationGallery($sourceGallery);
      $created += $result['created'];
      $updated += $result['updated'];
      $skipped += $result['skipped'];
    }

    return [
      'created' => $created,
      'updated' => $updated,
      'skipped' => $skipped,
    ];
  }

  /**
   * Updates the destination gallery items with the source values.
   *
   * @param array $sourceGalleryItems
   *   The source gallery items.
   * @param \Drupal\Core\Entity\EntityInterface|null $galleryEntity
   *   The gallery entity to update with media references. If NULL, galleries will be loaded by ID.
   *
   * @return array
   *   Returns the number of created/updated entities.
   *
   * @throws \Exception
   */
  protected function updateDestinationGalleryItems(array $sourceGalleryItems, $galleryEntity = NULL): array {
    $this->logger->notice('Creating/Updating the destination gallery items...');

    $created = 0;
    $updated = 0;
    $skipped = 0;
    $mediaEntities = [];

    // Initialize progress bar
    $this->initProgressBar(count($sourceGalleryItems), 'Processing gallery items');

    // Batch size for processing
    $batchSize = 20;
    $currentBatch = [];
    $batchCount = 0;
    $processedItems = 0;

    // Process items in batches
    foreach ($sourceGalleryItems as $sourceGalleryItem) {
      $processedItems++;

      // Skip if no media file
      if (empty($sourceGalleryItem['media_file'])) {
        $this->logger->warning(sprintf(
          'Skipping gallery item %s (ID: %d): Missing media file',
          $sourceGalleryItem['title'],
          $sourceGalleryItem['nid']
        ));
        ++$skipped;
        $this->advanceProgressBar();
        continue;
      }

      // Create media entity for the image
      $mediaFile = $sourceGalleryItem['media_file'];
      $file = $this->fileManager->createFile(
        $mediaFile['uri'],
        $mediaFile['name'],
        $mediaFile['content']
      );

      if (!$file) {
        $this->logger->warning(sprintf(
          'Skipping gallery item %s (ID: %d): Could not create file',
          $sourceGalleryItem['title'],
          $sourceGalleryItem['nid']
        ));
        ++$skipped;
        $this->advanceProgressBar();
        continue;
      }

      // Prepare media entity data
      $mediaData = [
        'file' => $file,
        'title' => $sourceGalleryItem['title'],
        'uid' => $sourceGalleryItem['uid'],
        'status' => $sourceGalleryItem['status'],
        'created' => $sourceGalleryItem['created'],
        'changed' => $sourceGalleryItem['changed'],
        'alt' => $mediaFile['alt'] ?? $sourceGalleryItem['title'],
        'title_attr' => $mediaFile['title'] ?? $sourceGalleryItem['title'],
        'gallery_ref' => $sourceGalleryItem['gallery_ref'],
      ];

      // Add to current batch
      $currentBatch[] = $mediaData;
      $batchCount++;

      // Process batch if we've reached batch size or this is the last item
      if ($batchCount >= $batchSize || $processedItems >= count($sourceGalleryItems)) {
        if (!$this->dryRun && !empty($currentBatch)) {
          // Use a transaction for batch saving
          $transaction = \Drupal::database()->startTransaction();
          try {
            foreach ($currentBatch as $mediaData) {
              // Check if media entity already exists
              $mediaEntity = $this->fileManager->mediaEntityExists('image', $mediaData['title']);

              if ($mediaEntity === NULL) {
                $mediaEntity = $this->entityTypeManager
                  ->getStorage('media')
                  ->create([
                    'bundle' => 'image',
                    'name' => $mediaData['title'],
                    'uid' => $mediaData['uid'],
                    'status' => $mediaData['status'],
                    'created' => $mediaData['created'],
                    'changed' => $mediaData['changed'],
                  ]);
                ++$created;
              }
              else {
                ++$updated;
              }

              // Set the image field
              $mediaEntity->set('field_media_image', [
                'target_id' => $mediaData['file']->id(),
                'alt' => $mediaData['alt'],
                'title' => $mediaData['title_attr'],
              ]);

              // Save the media entity
              $mediaEntity->save();

              // Store the media entity ID for later use
              $galleryRef = $mediaData['gallery_ref'];
              if ($galleryRef) {
                if (!isset($mediaEntities[$galleryRef])) {
                  $mediaEntities[$galleryRef] = [];
                }
                $mediaEntities[$galleryRef][] = $mediaEntity->id();
              }

              $this->advanceProgressBar();
            }

            // Clear the entity cache after each batch to free memory
            $this->entityTypeManager->getStorage('media')->resetCache();
          }
          catch (\Exception $e) {
            // If an error occurs, roll back the transaction
            if (isset($transaction)) {
              $transaction->rollBack();
            }
            $this->logger->error('Error saving media entities: ' . $e->getMessage());
            throw $e;
          }
        }

        // Reset batch
        $currentBatch = [];
        $batchCount = 0;
      }
    }

    // Update galleries with media references
    if (!empty($mediaEntities) && !$this->dryRun) {
      $this->logger->notice('Updating galleries with media references...');

      // If a specific gallery entity was provided, update only that one
      if ($galleryEntity) {
        $galleryId = $galleryEntity->id();
        if (isset($mediaEntities[$galleryId])) {
          $mediaIds = $mediaEntities[$galleryId];
          $galleryEntity->set('field_images', $mediaIds);

          try {
            $galleryEntity->save();
          }
          catch (\Exception $e) {
            $this->logger->error('Error updating gallery: ' . $e->getMessage());
          }
        }
      }
      else {
        // Otherwise, load and update all galleries with media references
        // Preload all galleries that need updating
        $galleryIds = array_keys($mediaEntities);
        $galleries = $this->entityTypeManager
          ->getStorage('node')
          ->loadMultiple($galleryIds);

        // Process galleries in batches
        $galleryBatchSize = 20;
        $galleryBatch = [];
        $galleryCount = 0;
        $processedGalleries = 0;

        foreach ($mediaEntities as $galleryId => $mediaIds) {
          $processedGalleries++;

          if (!isset($galleries[$galleryId])) {
            $this->logger->warning(sprintf(
              'Skipping gallery item references: Could not find gallery with ID %d',
              $galleryId
            ));
            ++$skipped;
            continue;
          }

          $gallery = $galleries[$galleryId];
          $gallery->set('field_images', $mediaIds);

          $galleryBatch[] = $gallery;
          $galleryCount++;

          // Save batch if we've reached batch size or this is the last gallery
          if ($galleryCount >= $galleryBatchSize || $processedGalleries >= count($mediaEntities)) {
            // Use a transaction for batch saving
            $transaction = \Drupal::database()->startTransaction();
            try {
              foreach ($galleryBatch as $galleryEntity) {
                $galleryEntity->save();
              }

              // Clear the entity cache after each batch to free memory
              $this->entityTypeManager->getStorage('node')->resetCache();
            }
            catch (\Exception $e) {
              // If an error occurs, roll back the transaction
              if (isset($transaction)) {
                $transaction->rollBack();
              }
              $this->logger->error('Error updating galleries: ' . $e->getMessage());
              throw $e;
            }

            // Reset batch
            $galleryBatch = [];
            $galleryCount = 0;
          }
        }
      }
    }

    return [
      'created' => $created,
      'updated' => $updated,
      'skipped' => $skipped,
    ];
  }

  /**
   * Finishes the process.
   *
   * @param int $created
   *   The number of created entities.
   * @param int $updated
   *   The number of updated entities.
   * @param int $skipped
   *   The number of skipped entities.
   */
  protected function tearDown(int $created, int $updated, int $skipped = 0): void {
    // Finish the progress bar if it exists
    if ($this->progressBar) {
      $this->progressBar->finish();
      $this->output->writeln('');
    }

    $timeElapsedSeconds = microtime(TRUE) - $this->startTime;
    $infoMessage = sprintf(
      "\n\nPROCESS FINISHED:\n"
      . "-- Time elapsed: %s.\n"
      . "-- %d entities created.\n"
      . "-- %d entities updated.\n"
      . "-- %d entities skipped.",
      gmdate("H:i:s", $timeElapsedSeconds),
      $created,
      $updated,
      $skipped
    );
    $this->logger->notice($infoMessage);
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
    if ($this->progressBar) {
      $this->progressBar->advance();
    }
  }


  /**
   * Disables the entity storage cache.
   *
   * @return void
   */
  protected function disableEntityStorageCache(): void {
    try {
      $this->logger->notice('Disabling entity storage caches...');
      $entityType = $this->entityTypeManager
        ->getStorage('node')
        ->getEntityType();
      $entityType->set('static_cache', FALSE);
      $entityType->set('persistent_cache', FALSE);
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error disabling the entity storage cache: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);
    }
  }

  /**
   * Enables the entity storage cache.
   *
   * @return void
   */
  protected function enableEntityStorageCache(): void {
    try {
      $this->logger->notice('Re-enabling entity storage caches...');
      $entityType = $this->entityTypeManager
        ->getStorage('node')
        ->getEntityType();
      $entityType->set('static_cache', TRUE);
      $entityType->set('persistent_cache', TRUE);
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error enabling the entity storage cache: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);
    }
  }

}
