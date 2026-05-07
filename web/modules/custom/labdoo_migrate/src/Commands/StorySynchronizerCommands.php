<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drupal\labdoo_migrate\Services\Media\FileManagerInterface;
use Drupal\labdoo_migrate\Services\Tracking\MigrationTrackerInterface;
use Drupal\labdoo_migrate\Traits\TextFormatMapperTrait;
use Drush\Commands\DrushCommands;
use Drupal\Core\Database\Query\Condition;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Content synchronization commands for Labdoo Stories.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class StorySynchronizerCommands extends DrushCommands { 

  use TextFormatMapperTrait;

  private const CONTENT_TYPE = 'labdoo_story';

  /**
   * The start time.
   *
   * @var mixed
   */
  private $startTime;

  /**
   * Optional UNIX timestamp filter for source nodes.
   *
   * @var int|null
   */
  private ?int $fromTimestamp = NULL;

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
   * StorySynchronizerCommands constructor.
   */
  public function __construct(
    protected ConnectionManagerInterface $externalConnectionManager,
    protected FileManagerInterface $fileManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MigrationTrackerInterface $migrationTracker
  ) {
    parent::__construct();
  }

  /**
   * Synchronizes Labdoo Stories taking a Drupal 7 instance as a source.
   *
   * @param array $options
   *   Command options.
   *
   * @command labdoo-synchronize-stories [nids=123,456,789] [limit=9] [dry-run] [from-date="YYYY-MM-DD HH:MM:SS"]
   * @aliases labdoo-sync-stories
   * @usage labdoo-synchronize-stories
   *   Synchronizes the contents of the type "labdoo_story".
   *
   * @option nids List of Drupal 9 IDs to synchronize.
   * @option limit Limits the execution to the given elements.
   * @option mode Defines if the entities must be created or updated (valid values: not defined, "create", "update").
   * @option dry-run Whether to run this command in dry-run mode. Specify this parameter to activate the dry-run mode.
   * @option from-date Date/time lower bound to filter stories by created/updated (format: "YYYY-MM-DD HH:MM:SS").
   */
  public function startSync(
    array $options = [
      'nids' => NULL,
      'limit' => -1,
      'dry-run' => FALSE,
      'from-date' => NULL,
    ]
  ): void {

    try {
      $this->setEnvironment($options);
      $sourceEntities = $this->getSourceEntities();
      $this->externalConnectionManager->restoreConnection();
      $result = $this->updateDestinationEntities($sourceEntities);
      $this->tearDown($result['created'], $result['updated']);
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
  protected function setEnvironment(array $options): void {
    \Drupal::state()->set('labdoo_migrate_is_running', TRUE);
    $this->fromTimestamp = NULL;
    $this->logger->notice('Setting the environment...');
    $this->startTime = microtime(TRUE);
    if ($options['nids'] !== NULL) {
      $this->nids = explode(',', $options['nids']);
    }
    $this->limit = $options['limit'];
    $this->dryRun = $options['dry-run'];
    if (!empty($options['from-date'])) {
      $ts = strtotime($options['from-date']);
      if ($ts === FALSE) {
        $this->logger->error(sprintf('Invalid value for option "from-date": %s. Expected format: YYYY-MM-DD HH:MM:SS', $options['from-date']));
        die;
      }
      $this->fromTimestamp = (int) $ts;
    }
  }

  /**
   * Retrieves the source entities.
   *
   * @return array
   *   Returns an array of source entities.
   *
   * @throws \Exception
   */
  protected function getSourceEntities(): array {
    /** @var int|null $this->fromTimestamp */
    $this->logger->notice('Retrieving the source entities...');

    $storiesResult = [];
    $storiesQuery = $this->externalConnectionManager
      ->setConnection()
      ->select('node', 'n')
      ->fields('n', ['nid', 'title', 'uid', 'status', 'created', 'changed'])
      ->condition('type', self::CONTENT_TYPE);
    if ($this->nids !== NULL) {
      $storiesQuery->condition('nid', $this->nids, 'IN');
    }
    if ($this->limit > -1) {
      $storiesQuery->range(0, $this->limit);
    }
    if (isset($this->fromTimestamp) && $this->fromTimestamp !== NULL) {
      $or = $storiesQuery->orConditionGroup()
        ->condition('created', $this->fromTimestamp, '>=')
        ->condition('changed', $this->fromTimestamp, '>=');
      $storiesQuery->condition($or);
    }
    $stories = $storiesQuery->execute()->fetchAll();

    foreach ($stories as $story) {
      $edooVillage = $this->externalConnectionManager
        ->setConnection()
        ->select('field_data_field_story_edoovillage', 'fev')
        ->fields('fev', ['field_story_edoovillage_target_id'])
        ->condition('entity_id', $story->nid)
        ->execute()
        ->fetchField();

      $sectionsResult = [];
      $sections = $this->externalConnectionManager
        ->setConnection()
        ->select('field_data_field_story_section', 'fss')
        ->fields('fss', ['field_story_section_revision_id'])
        ->condition('entity_type', 'node')
        ->condition('bundle', self::CONTENT_TYPE)
        ->condition('entity_id', $story->nid)
        ->execute()
        ->fetchAll();
      foreach ($sections as $section) {
        $heading = $this->getCollectionField(
          'field_data_field_story_heading',
          ['field_story_heading_value'],
          $section->field_story_section_revision_id
        );
        $text = $this->getCollectionField(
          'field_data_field_story_text',
          ['field_story_text_value', 'field_story_text_format'],
          $section->field_story_section_revision_id,
          FALSE
        );
        $picture = $this->getCollectionField(
          'field_data_field_story_picture',
          [
            'field_story_picture_fid',
            'field_story_picture_alt',
            'field_story_picture_title',
            'field_story_picture_width',
            'field_story_picture_height',
          ],
          $section->field_story_section_revision_id,
          FALSE
        );
        if ($picture) {
          $file = $this->externalConnectionManager
            ->setConnection()
            ->select('file_managed', 'fm')
            ->fields('fm', ['uri', 'filename'])
            ->condition('fid', $picture->field_story_picture_fid)
            ->execute()
            ->fetch();
          $picture->uri = $file->uri;
          $picture->name = $file->filename;
          $picture->content = $this->fileManager->getFileContents($file->uri, TRUE, TRUE);
        }

        $sectionsResult[] = [
          'heading' => $heading,
          'text' => $text,
          'picture' => $picture,
        ];
      }

      $storiesResult[] = [
        'nid' => $story->nid,
        'title' => $story->title,
        'uid' => $story->uid,
        'status' => $story->status,
        'created' => $story->created,
        'changed' => $story->changed,
        'edoovillage' => $edooVillage,
        'sections' => $sectionsResult,
      ];
    }

    $message = sprintf(
      '%d source entities found.',
      count($storiesResult)
    );
    $this->logger->notice($message);

    return $storiesResult;
  }

  /**
   * Updates the destination entities with the source values.
   *
   * @param array $sourceEntities
   *   The source entities.
   *
   * @return array
   *   Returns the number of created/updated entities.
   *
   * @throws \Exception
   */
  protected function updateDestinationEntities(array $sourceEntities): array {
    $this->logger->notice('Creating/Updating the destination entities...');

    $created = 0;
    $updated = 0;

    // Initialize progress bar
    $this->initProgressBar(count($sourceEntities), 'Processing stories');

    foreach ($sourceEntities as $sourceEntity) {
      $sections = $sourceEntity['sections'];
      $destinationEntity = $this->entityTypeManager
        ->getStorage('node')
        ->loadByProperties([
          'type' => self::CONTENT_TYPE,
          'nid' => $sourceEntity['nid'],
        ]);
      if (empty($destinationEntity)) {
        $destinationEntity = $this->entityTypeManager
          ->getStorage('node')
          ->create([
            'type' => self::CONTENT_TYPE,
            'nid' => $sourceEntity['nid'],
          ]);
        ++$created;
      }
      else {
        // Deletes the paragraphs to recreate them all.
        $destinationEntity = reset($destinationEntity);
        $paragraphs = $destinationEntity->get('field_story_section');
        foreach ($paragraphs as $paragraph) {
          if ($paragraph->entity === NULL) {
            continue;
          }

          $paragraph = $paragraph->entity;
          if (!$this->dryRun) {
            $paragraph->get('field_story_picture')->delete();
            $this->entityTypeManager
              ->getStorage('paragraph')
              ->delete([$paragraph]);
          }
        }
        ++$updated;
      }

      $paragraphs = [];
      foreach ($sections as $section) {
        $fid = NULL;
        if (
          !empty($section['picture'])
          && is_object($section['picture'])
          && empty($section['picture']->uri)
          && empty($section['picture']->name)
          && empty($section['picture']->content)
        ) {
          $file = $this->fileManager->createFile(
            $section['picture']->uri,
            $section['picture']->name,
            $section['picture']->content
          );
          $fid = $file->id();
        }

        $textValue = [
          'value' => (is_object($section['text'])) ? $section['text']->field_story_text_value : '',
          'format' => $this->mapFormat((is_object($section['text'])) ? $section['text']->field_story_text_format : NULL),
        ];
        $newParagraph = $this->entityTypeManager
          ->getStorage('paragraph')
          ->create([
            'type' => 'story_section',
            'field_story_heading' => $section['heading'],
            'field_story_text' => $textValue,
            'field_story_picture' => $fid,
          ]);
        if ($fid !== NULL && is_object($section['picture'])) {
          $newParagraph->set(
            'field_story_picture',
            [
              'alt' => $section['picture']->alt ?? '',
              'title' => $section['picture']->title ?? '',
            ]
          );
        }


        if (!$this->dryRun) {
          $newParagraph->save();
        }

        $paragraphs[] = $newParagraph;
      }

      $destinationEntity->set('nid', $sourceEntity['nid']);
      $destinationEntity->set('title', $sourceEntity['title']);
      $destinationEntity->set('uid', $sourceEntity['uid']);
      $destinationEntity->set('status', $sourceEntity['status']);
      $destinationEntity->set('created', $sourceEntity['created']);
      $destinationEntity->set('field_parent', $sourceEntity['edoovillage']);
      $destinationEntity->set('field_story_section', $paragraphs);
      $destinationEntity->set('changed', $sourceEntity['changed']);
      $destinationEntity->setChangedTime($sourceEntity['changed']);

      if (!$this->dryRun) {
        if (method_exists($destinationEntity, 'setSyncing')) {
          $destinationEntity->setSyncing(TRUE);
        }
        $destinationEntity->save();
        $this->migrationTracker->track(
          'node',
          self::CONTENT_TYPE,
          $sourceEntity['nid'],
          $destinationEntity->id(),
          (int) ((microtime(TRUE) - $this->startTime) * 1000)
        );
      }

      // Advance progress bar
      $this->advanceProgressBar();
    }

    return [
      'created' => $created,
      'updated' => $updated,
    ];
  }

  /**
   * Finishes the process.
   *
   * @param int $created
   *   The number of created entities.
   * @param int $updated
   *   The number of updated entities.
   */
  protected function tearDown(int $created, int $updated): void {
    \Drupal::state()->delete('labdoo_migrate_is_running');
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
      . "-- %d entities updated.",
      gmdate("H:i:s", $timeElapsedSeconds),
      $created,
      $updated
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
   * Retrieves a specific field from a collection.
   *
   * @param string $table
   *   The name of the table from which to retrieve the field.
   * @param array $fields
   *   The list of fields to be retrieved.
   * @param int $sectionId
   *   The ID of the section used to filter the query.
   * @param bool $fetchField
   *   If TRUE, uses fetchField(), otherwise fetch().
   *
   * @return mixed
   *   The fetched field value from the collection.
   *
   * @throws \Exception
   */
  protected function getCollectionField(
    string $table,
    array $fields,
    int $sectionId,
    bool $fetchField = TRUE
  ) {
    $result = $this->externalConnectionManager
      ->setConnection()
      ->select($table, $table)
      ->fields($table, $fields)
      ->condition('entity_type', 'field_collection_item')
      ->condition('bundle', 'field_story_section')
      ->condition('revision_id', $sectionId)
      ->execute();

    return $fetchField ? $result->fetchField() : $result->fetch();
  }

}
