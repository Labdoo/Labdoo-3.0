<?php

namespace Drupal\labdoo_common\Service\Repository;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * CommonRepository provides common functionalities and services for various operations within the application.
 *
 * The class is designed to encapsulate database interactions, caching, logging, and other shared utilities across modules.
 */
class CommonRepository {

  use StringTranslationTrait;

  /**
   * The Dootrip CO2 savings cache ID.
   */
  public const CO2_SAVINGS_CID = 'dootrip_co2_savings';

  /**
   * The database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cacheBackend;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * CommonRepository constructor.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   The cache backend.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    CacheBackendInterface $cacheBackend,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    LanguageManagerInterface $languageManager
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->cacheBackend = $cacheBackend;
    $this->logger = $loggerChannelFactory->get('labdoo_common');
    $this->languageManager = $languageManager;
  }

  /**
   * Retrieves the active countries.
   *
   * @param string|null $bundle
   *   Tbe bundle.
   *
   * @return array
   *   An array with the active countries.
   */
  public function getActiveCountries(?string $bundle = NULL): array {
    try {
      $query = $this->database->select('node__field_country', 'nfc');
      $query->distinct();
      $query->fields('nfc', ['field_country_value']);
      if ($bundle !== NULL) {
        $query->condition('nfc.bundle', $bundle);
      }
      $results = $query->execute()->fetchCol();
      $results = array_map('strtoupper', $results);
    }
    catch (\Exception $e) {
      if ($bundle !== NULL) {
        $errorMessage = sprintf(
          'Error retrieving the active countries for bundle %s: %s',
          $bundle,
          $e->getMessage()
        );
      }
      else {
        $errorMessage = sprintf(
          'Error retrieving the active countries: %s',
          $e->getMessage()
        );
      }
      $this->logger->error($errorMessage);

      return [];
    }
    if (empty($results)) {
      return [];
    }

    return $results;
  }

  /**
   * Retrieves the Dootronics number by status and EdooVillage.
   *
   * @param string|array|null $status
   *   Tbe status.
   * @param int|null $edooVillageId
   *   The edoovillage ID.
   * @param int|null $hubId
   *   The hub ID.
   * @param bool $useRevisionHistory
   *   Whether to use the revision history to count.
   *
   * @return int
   *   The number of Dootronics by the given status.
   */
  public function getDootronicsCountByStatus(
    $status = NULL,
    ?int $edooVillageId = NULL,
    ?int $hubId = NULL,
    bool $useRevisionHistory = FALSE
  ): int {
    $tableName = $useRevisionHistory ? 'node_revision__field_dootronic_status' : 'node__field_dootronic_status';
    $query = $this->database->select($tableName, 'nfs');
    $query->distinct();
    $query->fields('nfs', ['entity_id']);
    $query->condition('nfs.bundle', 'dootronic');
    if ($status !== NULL) {
      $op = is_array($status) ? 'IN' : '=';
      $query->condition('nfs.field_dootronic_status_value', $status, $op);
    }
    if ($edooVillageId !== NULL) {
      $query->addJoin('INNER', 'node__field_edoovillage_destination', 'ned', 'ned.entity_id = nfs.entity_id');
      $query->condition('ned.field_edoovillage_destination_target_id', $edooVillageId);
    }
    if ($hubId !== NULL) {
      $query->addJoin('INNER', 'node__field_hub', 'nh', 'nh.entity_id = nfs.entity_id');
      $query->condition('nh.field_hub_target_id', $hubId);
    }
    try {
      return (int) $query->countQuery()->execute()->fetchField();
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error retrieving the dootronics count by status: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return 0;
    }
  }

  /**
   * Retrieves the Dootronics needed by hub.
   *
   * @param int $hubId
   *   The hub ID.
   *
   * @return int
   *   The number of Dootronics by the given status.
   */
  public function getDootronicsNeededByHub(int $hubId): int {
    $query = $this->database->select('node__field_number_of_laptops_needed', 'nfnln');
    $query->addExpression('SUM(nfnln.field_number_of_laptops_needed_value)', 'total_laptops_needed');
    $query->addJoin('INNER', 'node__field_hub', 'nfh', 'nfh.entity_id = nfnln.entity_id');
    $query->condition('nfh.field_hub_target_id', $hubId);
    try {
      $results = $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error retrieving the dootronics needed by hub %d: %s',
        $hubId,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return 0;
    }

    return empty($results) ? 0 : $results;
  }

  /**
   * Gets the total number of objects of a given bundle.
   *
   * @param string $bundle
   *   The bundle.
   *
   * @return int
   *   The total number of objects.
   */
  public function getBundleCount(string $bundle): int {
    $query = $this->database->select('node_field_data', 'n');
    $query->condition('n.type', $bundle);
    try {
      return (int) $query->countQuery()->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->logger->error('Error getting bundle count for %bundle: %message', [
        '%bundle' => $bundle,
        '%message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Retrieves the total edoovillages.
   *
   * @param int|null $userId
   *   The user ID.
   *
   * @return int
   *   The total edoovillages.
   */
  public function getEdoovillagesCount(?int $userId = NULL): int {
    $query = $this->database->select('node_field_data', 'n');
    $query->addExpression('COUNT(*)');
    $query->condition('n.type', 'edoovillage');

    if ($userId !== NULL) {
      $query->condition('n.uid', $userId);
    }

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Retrieves the total students.
   *
   * @return int
   *   The total students.
   */
  public function getStudentsCount(): int {
    $query = $this->database->select('node__field_number_of_students', 's');
    $query->addExpression('SUM(field_number_of_students_value)');
    $query->condition('s.bundle', 'edoovillage');

    try {
      return (int) $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error retrieving the students count: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return 0;
    }
  }

  /**
   * Retrieves the total hubs.
   *
   * @param int|null $userId
   *   The user ID.
   *
   * @return int
   *   The total hubs.
   */
  public function getHubsCount(?int $userId = NULL): int {
    $query = $this->database->select('node_field_data', 'n');
    $query->addExpression('COUNT(*)');
    $query->condition('n.type', 'hub');

    if ($userId !== NULL) {
      $query->condition('n.uid', $userId);
    }

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Retrieves the total dootrips.
   *
   * @param int|null $userId
   *   The user ID.
   *
   * @return int
   *   The total dootrips.
   */
  public function getDootripsCount(?int $userId = NULL): int {
    $query = $this->database->select('node_field_data', 'n');
    $query->addExpression('COUNT(*)');
    $query->condition('n.type', 'dootrip');

    if ($userId !== NULL) {
      $query->condition('n.uid', $userId);
    }

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Retrieves the saved CO2.
   *
   * @param int $dootronicsDelivered
   *   The number of delivered dootronics.
   *
   * @return int
   *   The saved CO2.
   */

  public function getCo2Saved(int $dootronicsDelivered): float {
    $co2SavingsDootrip = $this->cacheBackend->get(self::CO2_SAVINGS_CID);
    if ($co2SavingsDootrip) {
      $co2SavingsDootrip = $co2SavingsDootrip->data;
    }
    else {
      $co2SavingsDootrip = \Drupal::state()->get(self::CO2_SAVINGS_CID);
      if ($co2SavingsDootrip === NULL) {
        /** @var \Drupal\labdoo_dootrip\Service\Compute\DootripComputeInterface $dootripCompute */
        $dootripCompute = \Drupal::service('labdoo_dootrip.compute');
        $dootripCompute->enqueueTotalCo2SavingsRecompute();
        $co2SavingsDootrip = 0;
      }
      else {
        // Cache it for performance if it was found in state.
        $this->cacheBackend->set(self::CO2_SAVINGS_CID, $co2SavingsDootrip, CacheBackendInterface::CACHE_PERMANENT, ['dootrip_co2_savings']);
      }
    }

    // Use historical count for CO2 savings by fabrication/recycling as in v2.
    $deliveredStates = ['S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'T1', 'T2'];
    $historicalDeliveredCount = $this->getDootronicsCountByStatus($deliveredStates, NULL, NULL, TRUE);

    // See these links for more information about this constant:
    // http://www.allgreenrecycling.com/ewaste-recycling-calculator/
    // http://www.co2list.org/files/carbon.htm#RANGE!A175
    $co2SavingsDootronic = $historicalDeliveredCount * 18.59;

    return (float) $co2SavingsDootrip + $co2SavingsDootronic;
  }

  /**
   * Retrieves the previous entity ID of the given type.
   *
   * @param int $entityId
   *   The entity ID.
   * @param string $type
   *   The entity type.
   *
   * @return int
   *   The previous entity ID.
   */
  public function getPreviousEntity(int $entityId, string $type): int {
    $query = $this->database->select('node_field_data', 'n');
    $query->join('node', 'base', 'n.nid = base.nid');
    $query->addField('n', 'nid');
    $query->condition('n.nid', $entityId, '<');
    $query->condition('n.type', $type);
    $query->condition('n.status', 1);
    $query->orderBy('n.nid', 'DESC');
    $query->range(0, 1);
    try {
      $prevNid = $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error retrieving the previous entity for %d: %s',
        $entityId,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return -1;
    }

    if ($prevNid) {
      return $prevNid;
    }

    return $this->getMaxEntity($type);
  }

  /**
   * Retrieves the next entity ID of the given type.
   *
   * @param int $entityId
   *   The entity ID.
   * @param string $type
   *   The entity type.
   *
   * @return int
   *   The next entity ID.
   */
  public function getNextEntity(int $entityId, string $type): int {
    $query = $this->database->select('node_field_data', 'n');
    $query->join('node', 'base', 'n.nid = base.nid');
    $query->addField('n', 'nid');
    $query->condition('n.nid', $entityId, '>');
    $query->condition('n.type', $type);
    $query->condition('n.status', 1);
    $query->orderBy('n.nid');
    $query->range(0, 1);
    try {
      $nextNid = $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error retrieving the next entity for %d: %s',
        $entityId,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return -1;
    }

    if ($nextNid) {
      return $nextNid;
    }

    return $this->getMinEntity($type);
  }

  /**
   * Retrieves the min entity ID of the given type.
   *
   * @param string $type
   *   The entity type.
   *
   * @return int
   *   The next entity ID.
   */
  public function getMinEntity(string $type): int {
    $query = $this->database->select('node_field_data', 'n');
    $query->join('node', 'base', 'n.nid = base.nid');
    $query->addExpression('MIN(n.nid)', 'max_nid');
    $query->condition('n.type', $type);
    $query->condition('n.status', 1);

    try {
      return $query->execute()->fetchField() ?? -1;
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error retrieving the min entity for %s: %s',
        $type,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return -1;
    }
  }

  /**
   * Retrieves the max entity ID of the given type.
   *
   * @param string $type
   *   The entity type.
   *
   * @return int
   *   The next entity ID.
   */
  public function getMaxEntity(string $type): int {
    $query = $this->database->select('node_field_data', 'n');
    $query->join('node', 'base', 'n.nid = base.nid');
    $query->addExpression('MAX(n.nid)', 'max_nid');
    $query->condition('n.type', $type);
    $query->condition('n.status', 1);

    try {
      return $query->execute()->fetchField() ?? -1;
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error retrieving the max entity for %s: %s',
        $type,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return -1;
    }
  }

  /**
   * Loads an entity.
   *
   * @param int $entityId
   *   The entity ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity or NULL in case of error.
   */
  public function loadEntity(int $entityId): ?EntityInterface {
    try {
      return $this->entityTypeManager
        ->getStorage('node')
        ->load($entityId);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $errorMessage = sprintf(
        'Error loading the entity with ID %d: %s',
        $entityId,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return NULL;
    }
  }

  /**
   * Retrieves a story entity based on the given parent ID.
   *
   * @param int $parentId
   *   The ID of the parent entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The story entity if found, or NULL on failure.
   */
  public function getStory(int $parentId): ?EntityInterface {
    try {
      $stories = $this->entityTypeManager
        ->getStorage('node')
        ->loadByProperties([
          'type' => 'labdoo_story',
          'field_parent' => $parentId,
        ]);

      if (empty($stories)) {
        return NULL;
      }

      return reset($stories);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $errorMessage = sprintf(
        'Error loading a story for entity with ID %d: %s',
        $parentId,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return NULL;
    }
  }

  /**
   * Gets the count of doojects associated with a user.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return int
   *   The count of doojects.
   */
  public function getUserDoojectsCount(int $userId): int {
    try {
      $query = $this->database->select('node_field_data', 'n');
      $query->condition('n.type', 'dootronic');
      $query->condition('n.uid', $userId);
      $query->condition('n.status', 1);
      $query->addExpression('COUNT(*)');
      return $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->logger->error('Error retrieving doojects count for user @uid: @message', [
        '@uid' => $userId,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Gets the count of dootrips associated with a user.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return int
   *   The count of dootrips.
   */
  public function getUserDootripsCount(int $userId): int {
    try {
      $query = $this->database->select('node_field_data', 'n');
      $query->condition('n.type', 'dootrip');
      $query->condition('n.uid', $userId);
      $query->condition('n.status', 1);
      $query->addExpression('COUNT(*)');
      return $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->logger->error('Error retrieving dootrips count for user @uid: @message', [
        '@uid' => $userId,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Gets the count of edoovillages associated with a user.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return int
   *   The count of edoovillages.
   */
  public function getUserEdoovillagesCount(int $userId): int {
    try {
      $query = $this->database->select('node_field_data', 'n');
      $query->condition('n.type', 'edoovillage');
      $query->condition('n.uid', $userId);
      $query->condition('n.status', 1);
      $query->addExpression('COUNT(*)');
      return $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->logger->error('Error retrieving edoovillages count for user @uid: @message', [
        '@uid' => $userId,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Gets the count of hubs associated with a user.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return int
   *   The count of hubs.
   */
  public function getUserHubsCount(int $userId): int {
    try {
      $query = $this->database->select('node_field_data', 'n');
      $query->condition('n.type', 'hub');
      $query->condition('n.uid', $userId);
      $query->condition('n.status', 1);
      $query->addExpression('COUNT(*)');
      return $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->logger->error('Error retrieving hubs count for user @uid: @message', [
        '@uid' => $userId,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Gets the count of wiki articles associated with a user.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return int
   *   The count of wiki articles.
   */
  public function getUserWikisCount(int $userId): int {
    try {
      $query = $this->database->select('node_field_data', 'n');
      $query->condition('n.type', 'wiki');
      $query->condition('n.uid', $userId);
      $query->condition('n.status', 1);
      $query->addExpression('COUNT(*)');
      return $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->logger->error('Error retrieving wikis count for user @uid: @message', [
        '@uid' => $userId,
        '@message' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * Formats a number according to the current language.
   *
   * @param float|int $number
   *   The number to format.
   * @param int $decimals
   *   The number of decimals.
   *
   * @return string
   *   The formatted number.
   */
  public function formatNumber($number, int $decimals = 0): string {
    $languageId = $this->languageManager->getCurrentLanguage()->getId();
    // Map Drupal language ID to a locale that NumberFormatter understands.
    // For many cases, the 2-letter code works, but sometimes we need more.
    // However, NumberFormatter often accepts just 'es' or 'en'.
    $formatter = new \NumberFormatter($languageId, \NumberFormatter::DECIMAL);

    $decimalSeparator = $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
    $thousandsSeparator = $formatter->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);

    return number_format((float) $number, $decimals, (string) $decimalSeparator, (string) $thousandsSeparator);
  }

}
