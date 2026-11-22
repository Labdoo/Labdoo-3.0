<?php

namespace Drupal\labdoo_map\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\labdoo_map\Service\MapPointRepository;
use Drupal\labdoo_map\Service\MapPointClusterer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Provides map data for dashboards.
 *
 * @phpstan-consistent-constructor
 */
class MapDataController extends ControllerBase {

  /**
   * Constructs the map controller.
   *
   * @param \Drupal\labdoo_map\Service\MapPointRepository $points
   *   The points.
   * @param \Drupal\labdoo_map\Service\MapPointClusterer $clusterer
   *   The clusterer.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatterInjected
   *   The date.formatter service.
   */
  public function __construct(
    private readonly MapPointRepository $points,
    private readonly MapPointClusterer $clusterer,
    protected DateFormatterInterface $dateFormatterInjected,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('labdoo_map.points'),
      $container->get('labdoo_map.clusterer'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Helper to check if a location is suspicious.
   *
   * @param float $lat
   *   The latitude.
   * @param float $lon
   *   The longitude.
   *
   * @return bool
   *   TRUE if the location is suspicious, FALSE otherwise.
   */
  public static function isSuspiciousLocation($lat, $lon) {
    // 0,0 is usually a sign of missing geocoding.
    if (abs($lat) < 0.0001 && abs($lon) < 0.0001) {
      return TRUE;
    }
    // Latitudes beyond the habitable range are suspicious.
    // South of -56.7 and North of 77.75 are considered polar/uninhabitable.
    if ($lat < -56.7 || $lat > 77.75) {
      return TRUE;
    }
    // Specific coordinates that are known to be "defaults" or incorrect.
    // E.g. Madrid or Barcelona city centers when only country/region was
    // provided.
    $defaults = [
    // Madrid.
      '40.416775,-3.703790',
    // Barcelona.
      '41.385064,2.173404',
    ];
    if (in_array(sprintf('%.6f,%.6f', $lat, $lon), $defaults)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Returns points for a specific content type.
   *
   * @param string $type
   *   The type.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   */
  public function getMapPoints(string $type, Request $request): CacheableJsonResponse {
    $zoom = 2;
    $viewport = NULL;
    $clustered = $request->query->get('_map') === 'clusters';
    if ($clustered) {
      $value = (string) $request->query->get('zoom', '2');
      if (!ctype_digit($value) || (int) $value > 18) {
        throw new BadRequestHttpException('Map zoom must be between 0 and 18.');
      }
      $zoom = (int) $value;
      if ($request->query->has('bbox')) {
        $parts = explode(',', $request->query->get('bbox'));
        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
          throw new BadRequestHttpException('Map bounds require four coordinates.');
        }
        $viewport = array_map('floatval', $parts);
        [$west, $south, $east, $north] = $viewport;
        if ($west < -180 || $west > 180 || $east < -180 || $east > 180 || $south < -90 || $north > 90 || $south > $north) {
          throw new BadRequestHttpException('Map bounds are outside the supported range.');
        }
      }
    }
    $collection = $this->points->getPoints($type, $request->query->all());
    $data = $clustered ? $this->clusterer->cluster($collection['points'], $zoom, $viewport) : $collection['points'];
    $metadata = $collection['cache'];
    $metadata->addCacheContexts(['url.query_args']);
    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($metadata);
    $response->setVary('Cookie', FALSE);
    if ($this->currentUser()->isAuthenticated() || $metadata->getCacheMaxAge() === 0) {
      $response->headers->set('Cache-Control', 'private, no-cache');
    }
    else {
      $response->setPublic()->setMaxAge($metadata->getCacheMaxAge());
    }
    return $response;
  }

  /**
   * Returns trajectory points for a specific node.
   *
   * @param mixed $nid
   *   The nid.
   */
  public function getNodeTrajectory($nid) {
    $unique_points = [];
    $node = $this->entityTypeManager()->getStorage('node')->load($nid);

    if (!$node) {
      return new JsonResponse([]);
    }

    // Try both field_location (single) and field_locations (multiple).
    $fields = ['field_location', 'field_locations'];

    $created = $node->getCreatedTime();
    $date_formatter = $this->dateFormatterInjected;
    $formatted_date = $date_formatter->format($created, 'short');

    foreach ($fields as $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $values = $node->get($field_name)->getValue();
        foreach ($values as $value) {
          $lat = (float) ($value['lat'] ?? $value['value_lat'] ?? 0);
          $lon = (float) ($value['lon'] ?? $value['value_lon'] ?? 0);

          if ($lat && $lon && !self::isSuspiciousLocation($lat, $lon)) {
            $key = sprintf('%.6f,%.6f', $lat, $lon);
            if (!isset($unique_points[$key])) {
              $unique_points[$key] = [
                'lat' => $lat,
                'lon' => $lon,
                'index' => count($unique_points) + 1,
                'date' => $formatted_date,
              ];
            }
          }
        }
      }
    }

    return new JsonResponse(array_values($unique_points));
  }

}
