<?php

namespace Drupal\mini_wiki\Plugin\rest\resource;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\mini_wiki\Service\MiniWikiRepository;
use Drupal\rest\Plugin\ResourceBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Implements common functions for REST resources.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
abstract class AbstractResource extends ResourceBase {

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
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\mini_wiki\Service\MiniWikiRepository $miniWikiRepository
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    protected MiniWikiRepository $miniWikiRepository
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
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('mini_wiki'),
      $container->get('mini_wiki.repository')
    );
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
