<?php

namespace Drupal\labdoo_dootronics\Service\Compute;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_dootronics\Exception\LockException;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;

/**
 * Service to compute Dootronics data.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 *
 * @property \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository
 * @property \Drupal\Core\Logger\LoggerChannelInterface $logger
 * @property \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository
 */
class DootronicCompute implements DootronicComputeInterface {

  /**
   * Class constructor.
   *
   * @param \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository
   *   The common repository instance.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory instance.
   */
  public function __construct(
    CommonRepository $commonRepository,
    DootronicRepositoryInterface $dootronicRepository,
    LoggerChannelFactoryInterface $loggerChannelFactory
  ) {
    $this->commonRepository = $commonRepository;
    $this->dootronicRepository = $dootronicRepository;
    $this->logger = $loggerChannelFactory->get('labdoo_dootronics');
  }

  /**
   * {@inheritDoc}
   */
  public function computeEdooVillageData(EntityInterface $entity): void {
    /** @var \Drupal\Core\Entity\EntityInterface $edooVillage */
    $edooVillage = $entity->get('field_edoovillage_destination')->entity;
    if ($edooVillage === NULL) {
      return;
    }

    $needed = $edooVillage->get('field_number_of_laptops_needed')->value;
    $delivered = $this->commonRepository->getDootronicsCountByStatus('S4', (int) $edooVillage->id());
    $inTransit = $this->commonRepository->getDootronicsCountByStatus('T1', (int) $edooVillage->id());
    $remaining = $needed - $delivered - $inTransit;
    if ($remaining < 0) {
      $remaining = 0;
    }

    $originalDelivered = (int) $edooVillage->get('field_dootronics_delivered')->value;
    $originalInTransit = (int) $edooVillage->get('field_dootronics_in_transit')->value;
    $originalRemaining = (int) $edooVillage->get('field_dootronics_remaining')->value;

    if (
      $originalDelivered === $delivered
      && $originalInTransit === $inTransit
      && $originalRemaining === $remaining
    ) {
      return;
    }

    $edooVillage->set('field_dootronics_delivered', $delivered);
    $edooVillage->set('field_dootronics_in_transit', $inTransit);
    $edooVillage->set('field_dootronics_remaining', $remaining);

    try {
      $edooVillage->save();
      \Drupal::entityTypeManager()->getStorage('node')->resetCache([$edooVillage->id()]);
    }
    catch (EntityStorageException $e) {
      $errorMessage = sprintf(
        'Error calculating the EdooVillage parameters for Dootronic with ID %d: %s',
        $entity->id(),
        $e->getMessage()
      );
      $this->logger->error($errorMessage);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function computeHubData(EntityInterface $entity): void {
    /** @var \Drupal\Core\Entity\EntityInterface $hub */
    $hub = $entity->get('field_hub')->entity;
    if ($hub === NULL) {
      return;
    }

    $needed = $this->commonRepository->getDootronicsNeededByHub($hub->id());
    $delivered = $this->commonRepository->getDootronicsCountByStatus('S4', NULL, (int) $hub->id());
    $inTransit = $this->commonRepository->getDootronicsCountByStatus('T1', NULL, (int) $hub->id());
    $remaining = $needed - $delivered - $inTransit;
    if ($remaining < 0) {
      $remaining = 0;
    }
    $completed = $needed === 0 ? 0 : $remaining * 100 / $needed;
    if ($completed < 0) {
      $completed = 0;
    }

    $originalNeeded = (int) $hub->get('field_dootronics_needed')->value;
    $originalDelivered = (int) $hub->get('field_dootronics_delivered')->value;
    $originalInTransit = (int) $hub->get('field_dootronics_in_transit')->value;
    $originalRemaining = (int) $hub->get('field_dootronics_remaining')->value;
    $originalCompleted = (int) $hub->get('field_dootronics_completed')->value;

    if (
      $originalNeeded === $needed
      && $originalDelivered === $delivered
      && $originalInTransit === $inTransit
      && $originalRemaining === $remaining
      && $originalCompleted === $completed
    ) {
      return;
    }

    $hub->set('field_dootronics_needed', $needed);
    $hub->set('field_dootronics_delivered', $delivered);
    $hub->set('field_dootronics_in_transit', $inTransit);
    $hub->set('field_dootronics_remaining', $remaining);
    $hub->set('field_dootronics_completed', $completed);

    try {
      $hub->save();
      \Drupal::entityTypeManager()->getStorage('node')->resetCache([$hub->id()]);
    }
    catch (EntityStorageException $e) {
      $errorMessage = sprintf(
        'Error calculating the hub parameters for Dootronic with ID %d: %s',
        $entity->id(),
        $e->getMessage()
      );
      $this->logger->error($errorMessage);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function setDootronicTitle(EntityInterface $entity): void {
    // If we are migrating nodes, we do not want an automatic ID to be generated.
    if (isset($entity->original_entity_id)) {
      // But we want to make sure that we are setting this value to the series.
      $sequenceNumber = (int) $entity->label();
      $title = $this->dootronicRepository->updateId($sequenceNumber);
      $entity->set('title', $title);
      $entity->set('field_tagged', TRUE);

      return;
    }

    // New nodes have their title already set in hook_entity_presave
    // using the SequenceManager.
    if ($entity->isNew()) {
      return;
    }

    try {
      $tagged = $entity->get('field_tagged')->value;
      if (!$tagged) {
        $sequenceNumber = $entity->id();
        $title = $this->dootronicRepository->updateId($sequenceNumber);
        $entity->set('title', $title);
        $entity->set('field_tagged', TRUE);
      }
    }
    catch (LockException $e) {
      $errorMessage = sprintf(
        'Could not set a Dootronic ID: %s',
        $e->getMessage()
      );
      $this->logger->error($errorMessage);
      $entity->set('title', '[ERROR]');
    }
  }

  /**
   * {@inheritDoc}
   */
  function computeWattHours(EntityInterface $entity): void {
    // If the Wh field is present, then print it in the label
    $volts = $entity->get('field_volts')->value;
    $ampHours = $entity->get('field_amp_hours')->value;
    $wattageHour = 'Not available';

    if (!empty($volts) && !empty($ampHours)) {
      $wh = round($volts * $ampHours / 1000, 1);
      $wattageHour = $wh . 'Wh';
    }

    $entity->set('field_battery_watt_hours', $wattageHour);
  }

  /**
   * {@inheritDoc}
   */
  public function computeRelatedDootrips(EntityInterface &$dootronic): void {
    foreach ($dootronic->get('field_dootrips') as $item) {
      $dootrip = $item->entity;
      if (!$dootrip) {
        continue;
      }
      $found = FALSE;

      foreach ($dootrip->get('field_laptops') as $dootronicAssigned) {
        $dootronicAssigned = $dootronicAssigned->entity;
        if ($dootronicAssigned && $dootronicAssigned->id() === $dootronic->id()) {
          $found = TRUE;
        }
      }

      if (!$found) {
        $dootrip->field_laptops->appendItem($dootronic);
        $dootrip->save();
        \Drupal::entityTypeManager()->getStorage('node')->resetCache([$dootrip->id()]);
      }
    }

    if (!isset($dootronic->original)) {
      return;
    }

    $originalDootrips = $dootronic->original->get('field_dootrips')->referencedEntities();
    $currentDootripIds = array_map(fn($entity) => $entity->id(), $dootronic->get('field_dootrips')->referencedEntities());

    foreach ($originalDootrips as $originalDootrip) {
      if (!in_array($originalDootrip->id(), $currentDootripIds)) {
        $laptops = $originalDootrip->get('field_laptops');
        foreach ($laptops as $index => $item) {
          if ($item->target_id == $dootronic->id()) {
            $laptops->removeItem($index);
            $originalDootrip->save();
            \Drupal::entityTypeManager()->getStorage('node')->resetCache([$originalDootrip->id()]);
            break;
          }
        }
      }
    }
  }

}
