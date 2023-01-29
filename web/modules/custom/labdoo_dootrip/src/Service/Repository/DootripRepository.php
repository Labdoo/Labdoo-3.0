<?php

namespace Drupal\labdoo_dootrip\Service\Repository;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Dootrip repository service.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DootripRepository implements DootripRepositoryInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * DootripRepository constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    Connection $database
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $loggerChannelFactory->get('labdoo_dootrip');
    $this->database = $database;
  }

  /**
   * {@inheritDoc}
   */
  public function disableEntityStorageCache(): void {
    $entityType = $this->entityTypeManager
      ->getStorage('node')
      ->getEntityType();
    $entityType->set('static_cache', FALSE);
    $entityType->set('persistent_cache', FALSE);
  }

  /**
   * {@inheritDoc}
   */
  public function enableEntityStorageCache(): void {
    $entityType = $this->entityTypeManager
      ->getStorage('node')
      ->getEntityType();
    $entityType->set('static_cache', TRUE);
    $entityType->set('permanent_cache', TRUE);
  }

  /**
   * {@inheritDoc}
   */
  public function load(int $dootripId): ?EntityInterface {
    try {
      return $this->entityTypeManager
        ->getStorage('node')
        ->load($dootripId);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $errorMessage = sprintf(
        'Error loading Dootrip by ID %d: %s',
        $dootripId,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return NULL;
    }
  }

  /**
   * {@inheritDoc}
   */
  public function getAllNids(): array {
    try {
      return $this->database
        ->select('node', 'n')
        ->fields('n', ['nid'])
        ->condition('type', 'dootrip')
        ->execute()
        ->fetchCol();
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error retrieving all the dootrip nids: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return [];
    }
  }

  /**
   * {@inheritDoc}
   */
  public function getNidsByConditions(array $joins, array $conditions): array {
    try {
      $query = $this->database
        ->select('node', 'n')
        ->fields('n', ['nid'])
        ->condition('type', 'dootrip');
      foreach ($joins as $join) {
        $query->addJoin(
          $join['type'],
          $join['table'],
          $join['alias'],
          $join['condition'],
        );
      }
      foreach ($conditions as $condition) {
        $query->condition(
          $condition['field'],
          $condition['value'],
          $condition['operator']
        );
      }

      return $query
        ->execute()
        ->fetchCol();
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error retrieving all the dootrip nids: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return [];
    }
  }

  /**
   * {@inheritDoc}
   */
  public function loadByProperties(array $properties): array {
    try {
      return $this->entityTypeManager
        ->getStorage('node')
        ->loadByProperties($properties);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $errorMessage = sprintf(
        'Error loading Dootrip by properties: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return [];
    }
  }

  /**
   * {@inheritDoc}
   */
  public function saveEntity(EntityInterface $dootrip): bool {
    try {
      return $dootrip->save();
    }
    catch (EntityStorageException $e) {
      $errorMessage = sprintf(
        'Error saving Dootrip: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return FALSE;
    }
  }

  public function getRelatedEdooVillages(int $dootripId): array {
    $subQuery = $this->database
      ->select('field_data_field_laptops', 'l')
      ->fields('l', ['field_laptops_target_id'])
      ->condition('l.entity_id', $dootripId);
    $query = $this->database
      ->select('field_data_field_edoovillage_destination', 'e')
      ->fields('e', ['field_edoovillage_destination_target_id'])
      ->condition('e.entity_id', $subQuery, 'IN');
    try {
      $edooVillagesIds = $query
        ->execute()
        ->fetchAll();

      return $this->entityTypeManager
        ->getStorage('node')
        ->loadMultiple($edooVillagesIds);
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error retrieving the edoovillages related to the dootrip %d: %s',
        $dootripId,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return [];
    }
  }

}
