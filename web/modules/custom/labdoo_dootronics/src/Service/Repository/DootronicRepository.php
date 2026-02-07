<?php

namespace Drupal\labdoo_dootronics\Service\Repository;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\labdoo_common\Event\InvalidateCacheTagsEvent;
use Drupal\labdoo_dootronics\Service\SequenceManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Dootronic repository.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DootronicRepository implements DootronicRepositoryInterface {

  /**
   * The sequence manager.
   *
   * @var \Drupal\labdoo_dootronics\Service\SequenceManagerInterface
   */
  protected SequenceManagerInterface $sequenceManager;

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
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * The database service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * DootronicRepository constructor.
   *
   * @param \Drupal\labdoo_dootronics\Service\SequenceManagerInterface $sequenceManager
   *   The sequence manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Database\Connection $database
   *   The database service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   */
  public function __construct(
    SequenceManagerInterface $sequenceManager,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    AccountProxyInterface $currentUser,
    EventDispatcherInterface $eventDispatcher,
    Connection $database,
    EntityFieldManagerInterface $entityFieldManager
  ) {
    $this->sequenceManager = $sequenceManager;
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $loggerChannelFactory->get('labdoo_dootronics');
    $this->currentUser = $currentUser;
    $this->eventDispatcher = $eventDispatcher;
    $this->database = $database;
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * {@inheritDoc}
   */
  public function generateId(): string {
    $sequenceNumber = $this->sequenceManager->get();
    $this->sequenceManager->commit();

    return str_pad($sequenceNumber, 9, '0', STR_PAD_LEFT);
  }

  /**
   * {@inheritDoc}
   */
  public function updateId(int $sequenceNumber): string {
    $this->sequenceManager->commit();

    return str_pad($sequenceNumber, 9, '0', STR_PAD_LEFT);
  }

  /**
   * {@inheritDoc}
   */
  public function load(int $dootronicId): ?EntityInterface {
    try {
      return $this->entityTypeManager
        ->getStorage('node')
        ->load($dootronicId);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $errorMessage = sprintf(
        'Error loading Dootronics by properties: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return NULL;
    }
  }

  /**
   * {@inheritDoc}
   */
  public function loadByLabel(string $dootronicLabel): ?EntityInterface {
    try {
      $result = $this->entityTypeManager
        ->getStorage('node')
        ->loadByProperties([
          'title' => $dootronicLabel,
        ]);

      $result = reset($result);

      return !empty($result) ? $result : NULL;
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $errorMessage = sprintf(
        'Error loading Dootronics by properties: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return NULL;
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
        'Error loading Dootronics by properties: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return [];
    }
  }

  /**
   * {@inheritDoc}
   */
  public function loadByIds(array $dootronicIds): array {
    try {
      return $this->entityTypeManager
        ->getStorage('node')
        ->loadMultiple($dootronicIds);
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $errorMessage = sprintf(
        'Error loading Dootronics by properties: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return [];
    }
  }

  /**
   * {@inheritDoc}
   */
  public function saveEntity(EntityInterface $dootronic): bool {
    try {
      return $dootronic->save();
    }
    catch (EntityStorageException $e) {
      $errorMessage = sprintf(
        'Error saving Dootronics: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return FALSE;
    }
  }

  /**
   * {@inheritDoc}
   */
  public function generateQrCode(
    EntityInterface $dootronic,
    int $size = 60
  ): string {
    $url = Url::fromRoute(
      'labdoo_dootronics.view_dootronic',
      [
        'dootronicLabel' => $dootronic->label(),
      ]
    )
      ->setAbsolute()
      ->toString();

    return sprintf(
      'https://api.qrserver.com/v1/create-qr-code/?size=%dx%d&data=%s',
      $size,
      $size,
      $url
    );
  }

  /**
   * {@inheritDoc}
   */
  public function computeWattHours(EntityInterface $dootronic): string {
    $volts = $dootronic->get('field_volts')->value;
    $ampHours = $dootronic->get('field_amp_hours')->value;
    if (empty($volts) || empty($ampHours)) {
      return 'Not available';
    }

    $Wh = round($volts * $ampHours / 1000, 1);

    return $Wh . 'Wh';
  }

  /**
   * {@inheritDoc}
   */
  public function follow(EntityInterface $dootronic): bool {
    $dootronic
      ->get('field_edoo_additional_followers')
      ->appendItem($this->currentUser->id());

    try {
      if ($dootronic->save()) {
        $this->clearCacheTag($dootronic);

        return TRUE;
      }

      return FALSE;
    }
    catch (EntityStorageException $e) {
      $errorMessage = sprintf(
        'Error following Dootronics %d with user %d: %s',
        $dootronic->id(),
        $this->currentUser->id(),
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return FALSE;
    }
  }

  /**
   * {@inheritDoc}
   */
  public function unfollow(EntityInterface $dootronic): bool {
    $delta = $this->isCurrentUserFollowingDootronic($dootronic);
    if ($delta !== FALSE) {
      $additionalFollowers = $dootronic->get('field_edoo_additional_followers');
      $additionalFollowers->removeItem($delta);
    }

    try {
      if ($dootronic->save()) {
        $this->clearCacheTag($dootronic);

        return TRUE;
      }

      return FALSE;
    }
    catch (EntityStorageException $e) {
      $errorMessage = sprintf(
        'Error unfollowing Dootronics %d with user %d: %s',
        $dootronic->id(),
        $this->currentUser->id(),
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return FALSE;
    }
  }

  /**
   * {@inheritDoc}
   */
  public function togglePickMeUp(EntityInterface $dootronic, bool $status): bool {
    $dootronic->set('field_pick_me_up', $status);

    try {
      if ($dootronic->save()) {
        $this->clearCacheTag($dootronic, TRUE);

        return TRUE;
      }

      return FALSE;
    }
    catch (EntityStorageException $e) {
      $errorMessage = sprintf(
        'Error toggling pick me up for Dootronics %d with user %d: %s',
        $dootronic->id(),
        $this->currentUser->id(),
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return FALSE;
    }
  }

  /**
   * {@inheritDoc}
   */
  public function isCurrentUserFollowingDootronic(EntityInterface $dootronic) {
    $additionalFollowers = $dootronic->get('field_edoo_additional_followers');
    $delta = FALSE;
    $i = 0;

    foreach ($additionalFollowers->getValue() as $value) {
      if ((int) $this->currentUser->id() === (int) $value['target_id']) {
        $delta = $i;

        break;
      }
      ++$i;
    }

    return $delta;
  }

  /**
   * Retrieves the dootronics in
   *
   * @param int $startingDootronicId
   * @param int $endingDootronicId
   *
   * @return array
   */
  public function getDootronicsInRange(
    int $startingDootronicId,
    int $endingDootronicId
  ): array {
    try {
      if ($startingDootronicId > $endingDootronicId) {
        [$startingDootronicId, $endingDootronicId] = [$endingDootronicId, $startingDootronicId];
      }

      $result = $this->database
        ->select('node_field_data', 'nfd')
        ->fields('nfd')
        ->condition('nfd.type', 'dootronic')
        ->condition('nfd.nid', [$startingDootronicId, $endingDootronicId], 'BETWEEN')
        ->execute()
        ->fetchAllAssoc('nid');

      if (empty($result)) {
        return [];
      }

      return $this->entityTypeManager
        ->getStorage('node')
        ->loadMultiple(array_keys($result));
    }
    catch (\Exception $e) {
      $errorMessage = sprintf(
        'Error loading dootronics in range: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      return [];
    }
  }

  /**
   * {@inheritDoc}
   */
  public function getfieldAllowedValues(
    string $entityType,
    string $bundle,
    string $fieldName
  ): array {
    $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions($entityType, $bundle);
    if (!isset($fieldDefinitions[$fieldName])) {
      return [];
    }

    $fieldDefinition = $fieldDefinitions[$fieldName];
    if (!$fieldDefinition instanceof FieldConfig) {
      return [];
    }

    $settings = $fieldDefinition->getSettings();
    if (!isset($settings['allowed_values']) && is_array($settings['allowed_values'])) {
      return [];
    }

    return $settings['allowed_values'];
  }

  /**
   * {@inheritDoc}
   */
  public function clone(EntityInterface $originalDootronic): EntityInterface {
    $newDootronic = $this->entityTypeManager
      ->getStorage('node')
      ->create([
        'type' => $originalDootronic->bundle(),
      ]);

    $ignoredFields = [
      'nid',
      'uuid',
      'vid',
      'revision_uid',
      'revision_log',
    ];

    foreach ($originalDootronic->getFields() as $fieldName => $field) {
      if (in_array($fieldName, $ignoredFields)) {
        continue;
      }

      $newDootronic->set($fieldName, $originalDootronic->get($fieldName)->getValue());
    }

    $newDootronic->set('title', '');
    $newDootronic->set('field_tagged', FALSE);

    return $newDootronic;
  }

  /**
   * {@inheritDoc}
   */
  public function setDootronicExternalData(
    EntityInterface $dootronic,
    array $data,
    string $tag,
    bool $edoovillageOnly
  ): void {
    // Dootronic's tag.
    $dootronic->set('title', $tag);

    // Set the status.
    // For example, some laptops may be tagged as S5 (waiting for repairs).
    $status = $data['state'] ?? 'S2';
    $dootronic->set('field_dootronic_status', $status);

    // Edoovillage.
    $this->setDootronicExternalValue($dootronic, 'edoovillage', 'field_edoovillage_destination');

    if ($edoovillageOnly) {
      return;
    }

    // Model.
    $modelData = [];
    if (isset($data['manufacturer'])) {
      $modelData[] = $data['manufacturer'];
    }
    if (isset($data['device_model'])) {
      $modelData[] = $data['device_model'];
    }
    if (isset($data['product_name'])) {
      $modelData[] = $data['product_name'];
    }
    $dootronic->set('field_model', implode('; ', $modelData));

    // Serial number.
    $this->setDootronicExternalValue($dootronic, 'serial_no', 'field_serial_number');

    // Number of cores.
    if (isset($data['cpu_count'])) {
      $possibleCoreCount = $this->getfieldAllowedValues(
        $dootronic->getEntityTypeId(),
        $dootronic->bundle(),
        'field_cpu_type'
      );
      $maxCoreCount = intval(end($possibleCoreCount));
      $coreCount = intval($data['cpu_count']);
      if ($coreCount > $maxCoreCount) {
        $coreCount = $maxCoreCount;
      }
      $dootronic->set('field_cpu_type', $coreCount);
    }

    // CPU speed.
    $this->setDootronicExternalValue($dootronic, 'cpu_speed_mhz', 'field_cpu');

    // Memory size.
    $this->setDootronicExternalValue($dootronic, 'memory_size_mb', 'field_memory');

    // Disk size.
    $diskSize = 0;
    if (isset($data['disk1_size_gb'])) {
      $diskSize += intval($data['disk1_size_gb']);
    }
    if (isset($data['disk2_size_gb'])) {
      $diskSize += intval($data['disk2_size_gb']);
    }
    $dootronic->set('field_hard_drive', $diskSize);

    // Operating system.
    $this->setDootronicExternalValue($dootronic, 'os_version', 'field_current_operating_system');

    // Notes.
    $additionalNotes = [];
    if (isset($data['notes'])) {
      $additionalNotes[] = $data['notes'];
    }
    if (isset($data['collected_via'])) {
      $additionalNotes[] = 'Collected via: ' . $data['collected_via'];
    }
    if (isset($data['donor_name'])) {
      $additionalNotes[] = 'Donated by: ' . $data['donor_name'];
    }
    if (isset($data['collection_date'])) {
      $additionalNotes[] = 'Collection date: ' . $data['collection_date'];
    }
    $dootronic->set('field_doojects_additional_notes', implode('; ', $additionalNotes));

    // Technical notes.
    $technicalNotes = [];
    if (isset($data['ts'])) {
      $technicalNotes[] = 'Date of quality check: ' . $data['ts'];
    }
    if (isset($data['cpu_model'])) {
      $technicalNotes[] = 'CPU model: ' . $data['cpu_model'];
    }
    if (isset($data['disk1_model'])) {
      $technicalNotes[] = 'disk1_model: ' . $data['disk1_model'];
    }
    if (isset($data['disk1_type'])) {
      $technicalNotes[] = 'disk1_type: ' . $data['disk1_type'];
    }
    if (isset($data['disk2_model'])) {
      $technicalNotes[] = 'disk2_model: ' . $data['disk2_model'];
    }
    if (isset($data['disk2_type'])) {
      $technicalNotes[] = 'disk2_type: ' . $data['disk2_type'];
    }
    if (isset($data['quality_check'])) {
      $technicalNotes[] = 'Failed quality check: ' . $data['quality_check'];
    }
    $dootronic->set('field_technical_notes', implode('; ', $technicalNotes));

    // Additional emails.
    if (isset($data['donor_email'])) {
      $dootronic->set('field_edoo_additional_notif_em', explode(',', $data['donor_email']));
    }
    else {
      $dootronic->set('field_edoo_additional_notif_em', []);
    }

    // Weight.
    if (isset($data['laptop_weight_kg'])) {
      $dootronic->set('field_weight', floatval($data['laptop_weight_kg']));
    }
    else {
      $dootronic->set('field_weight', NULL);
    }

    // Voltage and ampers.
    if (isset($data['battery_voltage'])) {
      $dootronic->set('field_volts', floatval($data['battery_voltage']));
      if (isset($data['battery_energy_mwh'])) {
        $ampHourInt = intval(floatval($data['battery_energy_mwh']) / floatval($data['battery_voltage']));
        $dootronic->set('field_amp_hours', $ampHourInt);
      }
    }

    // Keyboard type.
    $this->setDootronicExternalValue($dootronic, 'keyboard_layout', 'field_keyboard_type');

    // Hub.
    if (isset($data['hub'])) {
      $dootronic->set('field_hub', explode(',', $data['hub']));
    }
  }

  /**
   * Sets an external value.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootronic
   *   The dootronic entity.
   * @param string $dataKey
   *   The external data key.
   * @param string $fieldName
   *   The dootronic field name.
   *
   * @return void
   */
  protected function setDootronicExternalValue(
    EntityInterface $dootronic,
    string $dataKey,
    string $fieldName
  ): void {
    if (isset($data[$dataKey])) {
      $dootronic->set($fieldName, $data[$dataKey]);
    }
  }

  /**
   * Invalidates the dootronic cache tag.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootronic
   *   The entity.
   * @param bool $forAllUsers
   *   If set to TRUE, the cache will be regenerated for all users.
   *
   * @return void
   */
  protected function clearCacheTag(
    EntityInterface $dootronic,
    bool $forAllUsers = FALSE
  ): void {
    if ($forAllUsers) {
      $tag = sprintf(
        'dootronic:%d',
        $dootronic->id()
      );
    }
    else {
      $tag = sprintf(
        'dootronic:%d:%d',
        $dootronic->id(),
        $this->currentUser->id()
      );
    }
    $event = new InvalidateCacheTagsEvent();
    $event->setCacheTags([$tag]);
    $this->eventDispatcher->dispatch(
      $event,
      InvalidateCacheTagsEvent::EVENT_NAME
    );
  }

}
