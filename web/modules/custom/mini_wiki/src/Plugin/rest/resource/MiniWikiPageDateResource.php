<?php

namespace Drupal\mini_wiki\Plugin\rest\resource;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class MiniWikiPageDateResource.
 *
 * Provides a custom REST service for the MiniWikiPage entity.
 *
 * @RestResource(
 *   id = "mini_wiki_page_date_resource",
 *   label = "Mini Wiki Page (from date)",
 *   uri_paths = {
 *     "canonical" = "/api/mini-wiki-page/date/{date}",
 *   }
 * )
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MiniWikiPageDateResource extends AbstractResource {

  /**
   * Retrieves a resource response based on the given date.
   *
   * @param string $date
   *   The date used for retrieval in Y-m-d format.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The response containing the resource data or error message
   */
  public function get(string $date): ResourceResponse {
    $validation = $this->validateDate($date);
    if ($validation) {
      return $validation;
    }

    try {
      $entities = $this->miniWikiRepository->getModifiedEntities($date);
      $data = [];
      $cacheTags = [];

      /** @var \Drupal\mini_wiki\Entity\MiniWikiPage $entity */
      foreach ($entities as $entity) {
        $data[] = (int) $entity->id();
        $cacheTags[] = 'wiki-page:' . $entity->id();
      }
      $cacheTags[] = 'wiki-page:list-date';

      $cacheMetadata = $this->buildCacheMetadata($cacheTags);
      $response = new ResourceResponse($data);
      $response->addCacheableDependency($cacheMetadata);
    }
    catch (
      InvalidPluginDefinitionException
      | PluginNotFoundException $e
    ) {
      $errorMessage = sprintf(
        'Error loading mini wki pages from date %s: %s',
        $date,
        $e->getMessage()
      );
      $this->logger->error($errorMessage);

      $response = new ResourceResponse(
        $errorMessage,
        Response::HTTP_INTERNAL_SERVER_ERROR
      );
      $response->addCacheableDependency(self::NOT_CACHEABLE);
    }

    return $response;
  }

  /**
   * Validates the date format provided.
   *
   * @param string $date
   *   The input date.
   *
   * @return \Drupal\rest\ResourceResponse|null
   *   Returns a ResourceResponse with an error message if the date is invalid,
   *   or NULL if the date is valid.
   */
  protected function validateDate(string $date): ?ResourceResponse {
    $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
    if ($dateObj === FALSE || $dateObj->format('Y-m-d') !== $date) {
      $errorMessage = sprintf(
        'Invalid date format for %s. Expected format is Y-m-d.',
        $date
      );

      $response = new ResourceResponse(
        $errorMessage,
        Response::HTTP_BAD_REQUEST
      );
      $response->addCacheableDependency(self::NOT_CACHEABLE);

      return $response;
    }

    return NULL;
  }

}
