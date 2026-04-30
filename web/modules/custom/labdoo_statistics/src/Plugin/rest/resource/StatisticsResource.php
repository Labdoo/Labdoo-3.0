<?php

namespace Drupal\labdoo_statistics\Plugin\rest\resource;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_statistics\Constants;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class MiniWikiPageDateResource.
 *
 * Provides a custom REST service for the Labdoo statistics.
 *
 * @RestResource(
 *   id = "statistics_resource",
 *   label = "Statistics",
 *   uri_paths = {
 *     "canonical" = "/api/statistics",
 *   }
 * )
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class StatisticsResource extends ResourceBase {

  /**
   * Cache configuration to avoid the cache.
   */
  protected const NOT_CACHEABLE = [
    '#cache' => [
      'max-age' => 0,
    ],
  ];

  /**
   * AbstractResource constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository
   *   The common repository.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    protected CommonRepository $commonRepository,
    LoggerInterface $logger
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $serializer_formats,
      $logger
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    /** @var \Drupal\labdoo_common\Service\Repository\CommonRepository $commonRepository */
    $commonRepository = $container->get('labdoo_common.repository.common');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $commonRepository,
      $container->get('logger.factory')->get('mini_wiki')
    );
  }

  /**
   * Retrieves a resource response based on the given date.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The response containing the resource data or error message
   */
  public function get(): ResourceResponse {
    $dootronicsTagged = $this->commonRepository
      ->getDootronicsCountByStatus();
    $dootronicsDelivered = $this->commonRepository
      ->getDootronicsCountByStatus('S4');
    $edoovillages = $this->commonRepository
      ->getEdooVillagesCount();
    $students = $this->commonRepository
      ->getStudentsCount();
    $hubs = $this->commonRepository
      ->getHubsCount();
    $co2saved = $this->commonRepository
      ->getCo2Saved($dootronicsDelivered);
    $countries = count(
      $this->commonRepository
        ->getActiveCountries()
    );
    $data = [
      'dootronics_tagged' => $dootronicsTagged,
      'dootronics_delivered' => $dootronicsDelivered,
      'edoovillages' => $edoovillages,
      'students' => $students,
      'hubs' => $hubs,
      'co2' => $co2saved,
      'countries' => $countries,
    ];

    $cacheMetadata = $this->buildCacheMetadata(Constants::CACHE_TAGS);
    $response = new ResourceResponse($data);
    $response->addCacheableDependency($cacheMetadata);

    return $response;
  }

  /**
   * Builds cache metadata for the specified Mini Wiki Page ID.
   *
   * @param array $cacheTags
   *   The tags to be cached.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cache metadata object containing the cache settings.
   */
  protected function buildCacheMetadata(array $cacheTags): CacheableMetadata {
    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->setCacheTags($cacheTags);
    $cacheMetadata->setCacheContexts(['url']);
    $cacheMetadata->setCacheMaxAge(Cache::PERMANENT);

    return $cacheMetadata;
  }

}
