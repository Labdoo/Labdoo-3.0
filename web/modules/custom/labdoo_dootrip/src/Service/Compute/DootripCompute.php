<?php

namespace Drupal\labdoo_dootrip\Service\Compute;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_dootrip\Service\Queue\Feeder\QueueFeederInterface;
use Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface;

/**
 * Service to compute Dootrip data.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class DootripCompute implements DootripComputeInterface {

  /**
   * The dootrip repository.
   *
   * @var \Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface
   */
  protected DootripRepositoryInterface $dootripRepository;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cacheBackend;

  /**
   * The total CO2 savings queue feeder.
   *
   * @var \Drupal\labdoo_dootrip\Service\Queue\Feeder\QueueFeederInterface
   */
  protected QueueFeederInterface $totalCo2SavingsQueueFeeder;

  /**
   * DootripCompute repository.
   *
   * @param \Drupal\labdoo_dootrip\Service\Repository\DootripRepositoryInterface $dootripRepository
   *   The dootrip repository.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   The cache backend.
   * @param \Drupal\labdoo_dootrip\Service\Queue\Feeder\QueueFeederInterface $totalCo2SavingsQueueFeeder
   *   The total CO2 savings queue feeder.
   */
  public function __construct(
    DootripRepositoryInterface $dootripRepository,
    CacheBackendInterface $cacheBackend,
    QueueFeederInterface $totalCo2SavingsQueueFeeder
  ) {
    $this->dootripRepository = $dootripRepository;
    $this->cacheBackend = $cacheBackend;
    $this->totalCo2SavingsQueueFeeder = $totalCo2SavingsQueueFeeder;
  }

  /**
   * {@inheritDoc}
   */
  public function computeTotalCo2Savings(): float {
    $co2Saved = 0;

    $cachedValue = $this->cacheBackend->get(CommonRepository::CO2_SAVINGS_CID);
    if ($cachedValue) {
      return (float) $cachedValue;
    }

    // Retrieves all the dootrips.
    try {
      $this->dootripRepository->disableEntityStorageCache();
    } catch (\Exception $e) {
      return 0;
    }
    $dootripNids = $this->dootripRepository
      ->getAllNids();

    foreach ($dootripNids as $dootripNid) {
      $dootrip = $this->dootripRepository->load($dootripNid);
      $co2Saved += $this->calculateCo2Savings($dootrip);
    }

    try {
      $this->dootripRepository->enableEntityStorageCache();
    } catch (\Exception $e) {
    }

    $this->cacheBackend->set(CommonRepository::CO2_SAVINGS_CID, $co2Saved);

    return $co2Saved;
  }

  /**
   * {@inheritDoc}
   */
  public function computeCo2Savings(EntityInterface $dootrip): void {
    $co2SavingsKgms = $this->calculateCo2Savings($dootrip);
    $numDootronics = $this->getNumDootronics($dootrip);
    if ($numDootronics == 1) {
      $result = $co2SavingsKgms . t(' Kgms of CO2 emissions will be saved assuming one laptop is transported');
    }
    else {
      $result = $co2SavingsKgms . t(' Kgms of CO2 emissions saved');
    }

    $dootrip->set('field_co2_savings_dootrip', $result);

    // Recalculates the CO2 savings in the background.
    $this->totalCo2SavingsQueueFeeder->feedQueue($dootrip);
  }

  /**
   * {@inheritDoc}
   */
  public function computeDistance(EntityInterface $dootrip): void {
    $distance = $this->calculateDistance($dootrip);
    if ($distance !== NULL) {
      $dootrip->set('field_distance_dootrip', $distance);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function computeWeight(EntityInterface $dootrip): void {
    $dootronics = $dootrip->get('field_laptops');
    if (!$dootronics) {
      $result = t('No dootronics linked to this dootrip yet');
      $dootrip->set('field_dootrip_weight', $result);

      return;
    }

    $suffix = '';
    $prefix = '';
    $totalWeight = 0;

    foreach ($dootronics as $dootronic) {
      if (!$dootronic->entity) {
        continue;
      }

      $weight = $dootronic->entity->get('field_weight')->value;
      if (!$weight) {
        $suffix = t('More than ');
        $prefix = t(' (Please update the weight field in all the doojects for an accurate weight estimation)');

        continue;
      }
      $totalWeight += $weight;
    }

    $result = $totalWeight === 0
      ? t('Please update the weight of each individual dootronic')
      : t($suffix . $totalWeight . ' Kgms' . $prefix);

    $dootrip->set('field_dootrip_weight', $result);
  }

  /**
   * {@inheritDoc}
   */
  public function computeDootronicsAssigned(EntityInterface $dootrip): void {
    $dootronics = $dootrip->get('field_laptops')->getValue();
    $dootrip->set('field_number_of_doojects_assigne', count($dootronics));
  }

  /**
   * {@inheritDoc}
   */
  public function computeDootripCapacity(EntityInterface &$dootrip): void {
    $dootronicsList = $dootrip->get('field_laptops')->getValue();
    $numDootronics = count($dootronicsList);
    $nowDate = new \DateTime();
    $arrivalDate = $dootrip->get('field_arrival_date')->value;
    $arrivalDateObject = new \DateTime($arrivalDate);
    $timePastDootrip = $arrivalDateObject->diff($nowDate);
    $timePastDootripInt = (int) $timePastDootrip->format("%r%a");

    // Checks if the dootrip was completed or not and set values accordingly.
    if ($timePastDootripInt > 0) {
      $dootrip->set('field_dootronics_delivered', $numDootronics);
      $dootrip->set('field_dootronics_in_transit', 0);
    }
    else {
      $dootrip->set('field_dootronics_delivered', 0);
      $dootrip->set('field_dootronics_in_transit', $numDootronics);
    }

    // Marks the Dootrip as loaded if Capacity <= Transported + In Transit.
    $capacity = $dootrip->get('field_dootrip_capacity')->value;
    $delivered = $dootrip->get('field_dootronics_delivered')->value;
    $inTransit = $dootrip->get('field_dootronics_in_transit')->value;
    $dootrip->set('field_full', $capacity <= $delivered + $inTransit);
  }

  /**
   * {@inheritDoc}
   */
  public function computeDootripLocations(EntityInterface &$dootrip): void {
    $origin = $dootrip->get('field_origin_of_the_trip')->value;
    $destination = $dootrip->get('field_destination_of_the_trip')->value;
    $dootrip->set('field_locations', [
      $origin,
      $destination,
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function computeRelatedDootronics(EntityInterface &$dootrip): void {
    foreach ($dootrip->get('field_laptops') as $dootronic) {
      $dootronic = $dootronic->entity;
      if (!$dootronic) {
        continue;
      }

      $found = FALSE;

      foreach ($dootronic->get('field_dootrips') as $dootripAssigned) {
        if ($dootripAssigned && $dootripAssigned->target_id === $dootrip->id()) {
          $found = TRUE;
        }
      }

      if (!$found) {
        $dootronic->field_dootrips->appendItem($dootrip);
        $dootronic->save();
        \Drupal::entityTypeManager()->getStorage('node')->resetCache([$dootronic->id()]);
      }
    }

    if (!isset($dootrip->original)) {
      return;
    }

    $originalDootronics = $dootrip->original->get('field_laptops')->referencedEntities();
    $currentDootronicIds = array_map(fn($entity) => $entity->id(), $dootrip->get('field_laptops')->referencedEntities());

    foreach ($originalDootronics as $originalDootronic) {
      if (!in_array($originalDootronic->id(), $currentDootronicIds)) {
        $dootrips = $originalDootronic->get('field_dootrips');
        foreach ($dootrips as $index => $item) {
          if ($item->target_id == $dootrip->id()) {
            $dootrips->removeItem($index);
            $originalDootronic->save();
            \Drupal::entityTypeManager()->getStorage('node')->resetCache([$originalDootronic->id()]);
            break;
          }
        }
      }
    }
  }

  /**
   * Calculates the CO2 savings of a dootrip.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip.
   *
   * @return float
   *   The CO2 savings.
   */
  protected function calculateCo2Savings(EntityInterface $dootrip): float {
    // The dootronic weight field is textual, so assume here a reasonable value.
    $dootronicWeightKgms = 2.5;
    // See http://timeforchange.org/co2-emissions-shipping-goods
    $CO2gramsPerKgmPerKm = 0.5;
    $numDootronics = $this->getNumDootronics($dootrip);
    $dootripDistance = $this->calculateDistance($dootrip);
    if (!is_numeric($dootripDistance)) {
      $dootripDistance = 0;
    }

    return round($CO2gramsPerKgmPerKm *
      $numDootronics *
      $dootronicWeightKgms *
      $dootripDistance / 1000, 1);
  }

  /**
   * Calculates the distance between the source and the destination trips.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip.
   *
   * @return float|NULL
   *   The traveling distance of the dootrip in Kms. NULL in case of exception.
   */
  protected function calculateDistance(EntityInterface $dootrip): ?float {
    $origin = $dootrip->get('field_origin_of_the_trip')->getValue();
    if (empty($origin)) {
      return NULL;
    }

    $destination = $dootrip->get('field_destination_of_the_trip')->getValue();
    if (empty($destination)) {
      return NULL;
    }

    $srcLat = (float) $origin[0]['lat'];
    $srcLon = (float) $origin[0]['lon'];

    $dstLat = (float) $destination[0]['lat'];
    $dstLon = (float) $destination[0]['lon'];

    return round(
      $this->vincentyGreatCircleDistance(
        $srcLat,
        $srcLon,
        $dstLat,
        $dstLon) / 1000
    );
  }

  /**
   * Calculates the great-circle distance between two points, with
   * the Vincenty formula.
   *
   * @param float $latitudeFrom
   *   Latitude of start point in [deg decimal].
   * @param float $longitudeFrom
   *   Longitude of start point in [deg decimal].
   * @param float $latitudeTo
   *   Latitude of target point in [deg decimal].
   * @param float $longitudeTo
   *   Longitude of target point in [deg decimal].
   * @param float $earthRadius
   *   Mean earth radius in [m].
   *
   * @return float
   *   Distance between points in [m] (same as earthRadius)
   */
  protected function vincentyGreatCircleDistance(
    float $latitudeFrom,
    float $longitudeFrom,
    float $latitudeTo,
    float $longitudeTo,
    float $earthRadius = 6371000
  ): float {
    // Converts from degrees to radians.
    $latFrom = deg2rad($latitudeFrom);
    $lonFrom = deg2rad($longitudeFrom);
    $latTo = deg2rad($latitudeTo);
    $lonTo = deg2rad($longitudeTo);

    $lonDelta = $lonTo - $lonFrom;
    $a = pow(cos($latTo) * sin($lonDelta), 2) +
      pow(cos($latFrom) * sin($latTo) - sin($latFrom) * cos($latTo) * cos($lonDelta), 2);
    $b = sin($latFrom) * sin($latTo) + cos($latFrom) * cos($latTo) * cos($lonDelta);

    $angle = atan2(sqrt($a), $b);

    return $angle * $earthRadius;
  }

  /**
   * Retrieves the number of dootronics of the given dootrip.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootrip
   *   The dootrip.
   *
   * @return int
   *   The number of dootronics.
   */
  protected function getNumDootronics(EntityInterface $dootrip): int {
    $dootronics = $dootrip->get('field_laptops')->value;

    return !empty($dootronics) ? count($dootronics) : 1;
  }

}
