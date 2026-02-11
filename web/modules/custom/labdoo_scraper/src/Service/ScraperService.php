<?php

namespace Drupal\labdoo_scraper\Service;

use GuzzleHttp\ClientInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Drupal\Core\Database\Connection;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service to scrape URLs and verify slugs.
 */
class ScraperService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The URL matcher (router without access checks).
   *
   * @var \Symfony\Component\Routing\Matcher\UrlMatcherInterface
   */
  protected $router;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new ScraperService object.
   */
  public function __construct(ClientInterface $http_client, AliasManagerInterface $alias_manager, UrlMatcherInterface $router, Connection $database) {
    $this->httpClient = $http_client;
    $this->aliasManager = $alias_manager;
    $this->router = $router;
    $this->database = $database;
  }

  /**
   * Scrapes a URL and extracts links.
   *
   * @param string $url
   *   The URL to scrape.
   * @param bool $only_same_domain
   *   Whether to only include URLs from the same domain as the source.
   *
   * @return array
   *   An array of discovered URLs.
   */
  public function scrapeUrl(string $url, bool $only_same_domain = TRUE): array {
    $discovered_urls = [];
    $parsed_source = parse_url($url);
    $source_host = $parsed_source['host'] ?? '';

    try {
      $response = $this->httpClient->request('GET', $url);
      $html = (string) $response->getBody();

      if (empty($html)) {
        return [];
      }

      $dom = new \DOMDocument();
      libxml_use_internal_errors(true);
      $dom->loadHTML($html);
      libxml_clear_errors();

      $links = $dom->getElementsByTagName('a');
      foreach ($links as $link) {
        $href = $link->getAttribute('href');
        if (empty($href) || strpos($href, '#') === 0 || strpos($href, 'javascript:') === 0) {
          continue;
        }

        // Convert relative URLs to absolute if needed (simplified)
        if (strpos($href, 'http') !== 0) {
          $base = ($parsed_source['scheme'] ?? 'http') . '://' . $source_host;
          if (strpos($href, '/') === 0) {
            $href = $base . $href;
          } else {
            $href = $base . '/' . $href;
          }
        }

        // Filter by domain if requested.
        if ($only_same_domain) {
          $discovered_host = parse_url($href, PHP_URL_HOST);
          if ($discovered_host !== $source_host) {
            continue;
          }
        }

        $discovered_urls[] = $href;
      }
    } catch (GuzzleException $e) {
      // Log error or handle it.
      \Drupal::logger('labdoo_scraper')->error('Error scraping URL @url: @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
    }

    return array_unique($discovered_urls);
  }

  /**
   * Processes a discovered URL.
   *
   * @param string $source_url
   *   The source URL.
   * @param string $discovered_url
   *   The discovered URL.
   * @param bool $recursive
   *   Whether to continue scraping discovered URLs.
   */
  public function processDiscoveredUrl(string $source_url, string $discovered_url, bool $recursive = FALSE) {
    // Check if this URL has already been processed to avoid infinite loops.
    $exists = $this->database->select('labdoo_scraper_results', 'r')
      ->fields('r', ['id'])
      ->condition('discovered_url', $discovered_url)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($exists) {
      return;
    }

    $slug = $this->extractSlug($discovered_url);
    if (empty($slug)) {
      return;
    }

    $info = $this->verifySlug($slug);

    $this->database->insert('labdoo_scraper_results')
      ->fields([
        'source_url' => $source_url,
        'discovered_url' => $discovered_url,
        'slug' => $slug,
        'exists' => $info['exists'] ? 1 : 0,
        'entity_type' => $info['entity_type'] ?? NULL,
        'entity_id' => $info['entity_id'] ?? NULL,
        'created' => time(),
      ])
      ->execute();

    // If recursive is enabled and the URL belongs to the same domain,
    // scrape it for more URLs.
    if ($recursive) {
      $source_host = parse_url($source_url, PHP_URL_HOST);
      $discovered_host = parse_url($discovered_url, PHP_URL_HOST);

      if ($source_host === $discovered_host) {
        $new_urls = $this->scrapeUrl($discovered_url);
        if (!empty($new_urls)) {
          $queue = \Drupal::queue('labdoo_scraper_url_processor');
          foreach ($new_urls as $new_url) {
            $queue->createItem([
              'source_url' => $discovered_url,
              'discovered_url' => $new_url,
              'recursive' => TRUE,
            ]);
          }
        }
      }
    }
  }

  /**
   * Extracts the slug from a URL.
   *
   * @param string $url
   *   The URL.
   *
   * @return string
   *   The slug.
   */
  protected function extractSlug(string $url): string {
    $path = parse_url($url, PHP_URL_PATH);
    if (empty($path)) {
      return '';
    }
    return trim($path, '/');
  }

  /**
   * Verifies if a slug exists in the portal.
   *
   * @param string $slug
   *   The slug.
   *
   * @return array
   *   An array with 'exists', 'entity_type', and 'entity_id'.
   */
  public function verifySlug(string $slug): array {
    $result = [
      'exists' => FALSE,
      'entity_type' => NULL,
      'entity_id' => NULL,
    ];

    // Check if it's a path alias.
    $path = '/' . $slug;
    $system_path = $this->aliasManager->getPathByAlias($path);

    if ($system_path !== $path) {
      $result['exists'] = TRUE;
      // Try to extract entity info from system path like /node/123
      if (preg_match('/^\/([a-z_]+)\/(\d+)$/', $system_path, $matches)) {
        $result['entity_type'] = $matches[1];
        $result['entity_id'] = $matches[2];
      }
      return $result;
    }

    // Check if it's a direct system path.
    try {
      $parameters = $this->router->match($path);
      if ($parameters) {
        $result['exists'] = TRUE;
        // Search for entity in parameters.
        foreach ($parameters as $key => $value) {
          if (is_object($value) && method_exists($value, 'getEntityTypeId')) {
            $result['entity_type'] = $value->getEntityTypeId();
            $result['entity_id'] = $value->id();
            break;
          }
        }
      }
    } catch (\Exception $e) {
      // Path not found.
    }

    return $result;
  }

}
