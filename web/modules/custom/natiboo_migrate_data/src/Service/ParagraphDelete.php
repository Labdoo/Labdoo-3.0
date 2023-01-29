<?php

namespace Drupal\natiboo_migrate_data\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Batch\BatchBuilder;

/**
 * Service to delete all paragraphs from the database.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ParagraphDelete {

  use StringTranslationTrait;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a new instance of the class.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    protected MessengerInterface $messenger,
  ) {
    $this->logger = $loggerChannelFactory->get('natiboo_migrate_data');
  }

  /**
   * Deletes all paragraphs from the database.
   *
   * @param bool $useBatch
   *   Whether to use batch processing for deletion.
   *
   * @return int
   *   The number of paragraphs deleted.
   */
  public function deleteAllParagraphs(bool $useBatch = TRUE): int {
    try {
      // Get the total count of paragraphs.
      $count = $this->getParagraphCount();
      
      if ($count === 0) {
        $this->messenger->addStatus($this->t('No paragraphs found to delete.'));
        return 0;
      }

      if ($useBatch && $count > 100) {
        // Use batch processing for large numbers of paragraphs.
        $this->createBatch($count);
        return $count;
      }
      else {
        // Delete paragraphs directly for smaller numbers.
        return $this->deleteParagraphsDirectly();
      }
    }
    catch (\Exception $e) {
      $this->logger->error(
        'Error deleting paragraphs: @error',
        ['@error' => $e->getMessage()]
      );
      $this->messenger->addError(
        $this->t(
          'Error deleting paragraphs: @error',
          ['@error' => $e->getMessage()]
        )
      );
      return 0;
    }
  }

  /**
   * Gets the total count of paragraphs in the database.
   *
   * @return int
   *   The number of paragraphs.
   */
  public function getParagraphCount(): int {
    $query = $this->entityTypeManager->getStorage('paragraph')->getQuery()->accessCheck();
    $result = $query->count()->execute();
    return (int) $result;
  }

  /**
   * Deletes all paragraphs directly without batch processing.
   *
   * @return int
   *   The number of paragraphs deleted.
   */
  protected function deleteParagraphsDirectly(): int {
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $query = $storage->getQuery()->accessCheck();
    $ids = $query->execute();
    
    if (empty($ids)) {
      return 0;
    }
    
    $count = count($ids);
    $chunks = array_chunk($ids, 50, TRUE);
    
    foreach ($chunks as $chunk) {
      $entities = $storage->loadMultiple($chunk);
      $storage->delete($entities);
    }
    
    $this->messenger->addStatus(
      $this->t('Successfully deleted @count paragraphs.', ['@count' => $count])
    );
    
    return $count;
  }

  /**
   * Creates a batch process for deleting paragraphs.
   *
   * @param int $count
   *   The total number of paragraphs to delete.
   */
  protected function createBatch(int $count): void {
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $query = $storage->getQuery()->accessCheck();
    $ids = $query->execute();
    
    if (empty($ids)) {
      return;
    }
    
    // Split the IDs into chunks for batch processing.
    $chunks = array_chunk($ids, 50, TRUE);
    
    $batch_builder = new BatchBuilder();
    $batch_builder
      ->setTitle($this->t('Deleting paragraphs'))
      ->setInitMessage($this->t('Preparing to delete paragraphs...'))
      ->setProgressMessage($this->t('Deleting paragraphs...'))
      ->setErrorMessage($this->t('Error deleting paragraphs.'));
    
    foreach ($chunks as $chunk) {
      $batch_builder->addOperation(
        [$this, 'batchDeleteParagraphs'],
        [$chunk]
      );
    }
    
    $batch_builder->setFinishCallback([$this, 'batchFinished']);
    
    batch_set($batch_builder->toArray());
  }

  /**
   * Batch operation callback for deleting paragraphs.
   *
   * @param array $ids
   *   An array of paragraph IDs to delete.
   * @param array $context
   *   The batch context.
   */
  public function batchDeleteParagraphs(array $ids, array &$context): void {
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $entities = $storage->loadMultiple($ids);
    $storage->delete($entities);
    
    // Update progress information.
    if (!isset($context['results']['count'])) {
      $context['results']['count'] = 0;
    }
    $context['results']['count'] += count($ids);
    
    $context['message'] = $this->t(
      'Deleted @count paragraphs so far...',
      ['@count' => $context['results']['count']]
    );
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   The batch results.
   * @param array $operations
   *   The operations that remained unprocessed.
   */
  public function batchFinished(bool $success, array $results, array $operations): void {
    if ($success) {
      $count = $results['count'] ?? 0;
      $this->messenger->addStatus(
        $this->t('Successfully deleted @count paragraphs.', ['@count' => $count])
      );
    }
    else {
      $this->messenger->addError(
        $this->t('An error occurred while deleting paragraphs.')
      );
    }
  }

}