<?php

namespace Drupal\labdoo_migrate\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\labdoo_migrate\Services\Database\ConnectionManagerInterface;
use Drupal\labdoo_migrate\Services\DestinationContent\TranslationRepositoryInterface;
use Drupal\labdoo_migrate\Services\Media\FileManagerInterface;
use Drupal\labdoo_migrate\Services\SourceContent\TranslationRepositoryInterface as SourceTranslationRepositoryInterface;
use Drupal\labdoo_migrate\Traits\TextFormatMapperTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Content synchronization commands for Drupal 7 Basic Pages.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class BasicPageSynchronizerCommands extends DrushCommands {

  use TextFormatMapperTrait;

  private const CONTENT_TYPE = 'page';
  private const DESTINATION_CONTENT_TYPE = 'page';

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
   * The running mode.
   *
   * @var bool
   */
  private bool $create;

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
   * The source translation repository.
   *
   * @var \Drupal\labdoo_migrate\Services\SourceContent\TranslationRepositoryInterface
   */
  protected SourceTranslationRepositoryInterface $sourceTranslationRepository;

  /**
   * The destination translation repository.
   *
   * @var \Drupal\labdoo_migrate\Services\DestinationContent\TranslationRepositoryInterface
   */
  protected TranslationRepositoryInterface $destinationTranslationRepository;

  /**
   * The translations count.
   *
   * @var int
   */
  private int $translationsCount = 0;

  /**
   * BasicPageSynchronizerCommands constructor.
   */
  public function __construct(
    protected ConnectionManagerInterface $externalConnectionManager,
    protected FileManagerInterface $fileManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    SourceTranslationRepositoryInterface $sourceTranslationRepository,
    TranslationRepositoryInterface $destinationTranslationRepository,
    protected LanguageManagerInterface $languageManager
  ) {
    parent::__construct();
    $this->sourceTranslationRepository = $sourceTranslationRepository;
    $this->destinationTranslationRepository = $destinationTranslationRepository;
  }

  /**
   * Synchronizes Basic Pages taking a Drupal 7 instance as a source.
   *
   * @param array $options
   *   Command options.
   *
   * @command labdoo-synchronize-basic-pages [nids=123,456,789] [limit=9] [dry-run]
   * @aliases labdoo-sync-basic-pages
   * @usage labdoo-synchronize-basic-pages
   *   Synchronizes the contents of the type "page" (basic page).
   *
   * @option nids List of Drupal 7 IDs to synchronize.
   * @option limit Limits the execution to the given elements.
   * @option dry-run Whether to run this command in dry-run mode. Specify this parameter to activate the dry-run mode.
   */
  public function startSync(
    array $options = [
      'nids' => NULL,
      'limit' => -1,
      'dry-run' => FALSE,
    ]
  ): void {
    try {
      $this->setEnvironment($options);

      $this->logger->notice('Retrieving the source entities IDs...');
      $pagesQuery = $this->externalConnectionManager
        ->setConnection()
        ->select('node', 'n')
        ->fields('n', ['nid', 'language', 'tnid'])
        ->condition('type', self::CONTENT_TYPE)
        ->condition(
          $this->externalConnectionManager->setConnection()->condition('OR')
            ->condition('tnid', 0)
            ->where('nid = tnid')
        );

      if ($this->nids !== NULL) {
        $pagesQuery->condition('nid', $this->nids, 'IN');
      }
      if ($this->limit > -1) {
        $pagesQuery->range(0, $this->limit);
      }
      $pages = $pagesQuery->execute()->fetchAll();
      $total = count($pages);
      $this->logger->notice(sprintf('%d source entities found.', $total));
      $this->externalConnectionManager->restoreConnection();

      $this->logger->notice('Updating the destination entities...');
      $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];
      $this->initProgressBar($total, 'Processing pages');

      foreach ($pages as $page) {
        $defaultLangcode = $this->languageManager->getDefaultLanguage()->getId();
        $mainLangCode = $page->language ?: $defaultLangcode;
        $entityId = $page->nid;

        // Initialize the entity structure for a single page
        $singlePageData = [
          $entityId => [
            'metadata' => [
              'main_langcode' => $mainLangCode
            ],
            $mainLangCode => $this->getPageData($entityId, $mainLangCode)
          ]
        ];

        // Get translations
        if ($page->tnid > 0) {
          $translations = $this->sourceTranslationRepository->getTranslations($entityId, $mainLangCode);
          foreach ($translations as $translation) {
            $translationId = $translation->getId();
            $translationLangCode = $translation->getLangCode();
            $singlePageData[$entityId][$translationLangCode] = $this->getPageData($translationId, $translationLangCode);
            $singlePageData[$entityId]['metadata'][$translationLangCode] = $translationId;
          }
        }

        $processResult = $this->updateDestinationEntities($singlePageData);
        $result['created'] += $processResult['created'];
        $result['updated'] += $processResult['updated'];
        $result['skipped'] += $processResult['skipped'];
        $this->advanceProgressBar();
      }

      $this->tearDown($result['created'], $result['updated'], $result['skipped']);
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
    $this->logger->notice('Setting the environment...');
    $this->startTime = microtime(TRUE);
    if ($options['nids'] !== NULL) {
      $this->nids = explode(',', $options['nids']);
    }
    $this->limit = $options['limit'];
    $this->dryRun = $options['dry-run'];
  }


  /**
   * Gets the page data for a specific node ID and language.
   *
   * @param int $nodeId
   *   The node ID.
   * @param string $langCode
   *   The language code.
   *
   * @return array
   *   Returns the page data.
   *
   * @throws \Exception
   */
  protected function getPageData(int $nodeId, string $langCode): array {
    // Get the node data
    $page = $this->externalConnectionManager
      ->setConnection()
      ->select('node', 'n')
      ->fields('n', ['nid', 'title', 'uid', 'status', 'created', 'changed'])
      ->condition('nid', $nodeId)
      ->execute()
      ->fetch();

    if (!$page) {
      return [];
    }

    // Get the body field
    $body = $this->externalConnectionManager
      ->setConnection()
      ->select('field_data_body', 'fdb')
      ->fields('fdb', ['body_value', 'body_format'])
      ->condition('entity_id', $nodeId)
      ->condition('entity_type', 'node')
      ->condition('bundle', self::CONTENT_TYPE)
      ->execute()
      ->fetch();

    return [
      'nid' => $page->nid,
      'title' => $page->title,
      'uid' => $page->uid,
      'status' => $page->status,
      'created' => $page->created,
      'changed' => $page->changed,
      'body' => $body ? $body->body_value : '',
      'body_format' => $body ? $body->body_format : 'basic_html',
      'language' => $langCode,
    ];
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
    $skipped = 0;
    $this->translationsCount = 0;

    // Initialize progress bar
    $this->initProgressBar(count($sourceEntities), 'Processing basic pages');

    foreach ($sourceEntities as $entityId => $sourceEntity) {
      $metadata = $sourceEntity['metadata'] ?? [];
      $defaultLangcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
      $mainLangCode = $metadata['main_langcode'] ?? $defaultLangcode;
      $mainEntityValues = $sourceEntity[$mainLangCode] ?? null;

      if (!$mainEntityValues) {
        ++$skipped;
        $this->advanceProgressBar();
        continue;
      }

      if (empty($mainEntityValues['title'])) {
        ++$skipped;
        $this->advanceProgressBar();
        continue;
      }

      if ($mainEntityValues['body_format'] === 'php_code') {
        ++$skipped;
        $this->advanceProgressBar();
        continue;
      }

      // Create or load the main entity
      $destinationEntity = $this->entityTypeManager
        ->getStorage('node')
        ->loadByProperties([
          'type' => self::DESTINATION_CONTENT_TYPE,
          'nid' => $entityId,
        ]);

      if (empty($destinationEntity)) {
        $destinationEntity = $this->entityTypeManager
          ->getStorage('node')
          ->create([
            'type' => self::DESTINATION_CONTENT_TYPE,
            'nid' => $entityId,
            'langcode' => $mainLangCode,
          ]);
        ++$created;
      } else {
        $destinationEntity = reset($destinationEntity);
        ++$updated;
      }

      // Update the main entity
      $this->updateEntityWithValues($destinationEntity, $mainEntityValues, $mainLangCode);

      // Create/update translations
      foreach ($sourceEntity as $langCode => $values) {
        // Skip metadata and main language
        if ($langCode === 'metadata' || $langCode === $mainLangCode) {
          continue;
        }

        if (empty($values) || empty($values['title'])) {
          continue;
        }

        // Get or create the translation
        $translation = $this->destinationTranslationRepository->getEntityTranslation(
          $destinationEntity,
          $langCode
        );

        // Update the translation
        $this->updateEntityWithValues($translation, $values, $langCode);
        ++$this->translationsCount;
      }

      // Advance progress bar
      $this->advanceProgressBar();
    }

    return [
      'created' => $created,
      'updated' => $updated,
      'skipped' => $skipped,
    ];
  }

  /**
   * Updates an entity with the given values.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to update.
   * @param array $values
   *   The values to set.
   * @param string $langCode
   *   The language code.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function updateEntityWithValues($entity, array $values, string $langCode): void {
    // Convert Drupal 7 format to Drupal 10 format
    $format = $this->mapFormat($values['body_format']);

    $body = [
      'value' => $values['body'],
      'format' => $format,
    ];

    $entity->set('title', $values['title']);
    $entity->set('uid', $values['uid']);
    $entity->set('status', $values['status']);
    $entity->set('created', $values['created']);
    $entity->set('changed', $values['changed']);
    if (method_exists($entity, 'setChangedTime')) {
      $entity->setChangedTime($values['changed']);
    }
    $entity->set('body', $body);

    if (!$this->dryRun) {
      if (method_exists($entity, 'setSyncing')) {
        $entity->setSyncing(TRUE);
      }
      $entity->save();
    }
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
  protected function tearDown(int $created, int $updated, int $skipped): void {
    \Drupal::state()->delete('labdoo_migrate_is_running');
    // Finish the progress bar if it exists
    if ($this->progressBar) {
      $this->progressBar->finish();
      $this->output->writeln('');
    }

    $timeElapsedSeconds = microtime(TRUE) - $this->startTime;
    $total = $created + $updated;
    $infoMessage = sprintf(
      "\n\nPROCESS FINISHED:\n"
      . "-- Time elapsed: %s.\n"
      . "-- %d entities created.\n"
      . "-- %d entities updated.\n"
      . "-- %d entities skipped.\n"
      . "-- %d translations processed.\n"
      . "-- %d total entities processed (main and translations combined).",
      gmdate("H:i:s", $timeElapsedSeconds),
      $created,
      $updated,
      $skipped,
      $this->translationsCount,
      $total + $this->translationsCount
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
}
