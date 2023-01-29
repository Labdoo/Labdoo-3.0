<?php

namespace Drupal\mini_wiki\Plugin\rest\resource;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\rest\ResourceResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class MiniWikiPageResource.
 *
 * Provides a custom REST service for the MiniWikiPage entity.
 *
 * @RestResource(
 *   id = "mini_wiki_page_resource",
 *   label = "Mini Wiki Page",
 *   uri_paths = {
 *     "canonical" = "/api/mini-wiki-page/{id}",
 *   }
 * )
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class MiniWikiPageResource  extends AbstractResource {

  /**
   * Retrieves a resource response based on the provided ID.
   *
   * @param int $id
   *   The ID of the resource to retrieve.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The response containing the resource data.
   */
  public function get(int $id): ResourceResponse {
    try {
      $entity = $this->miniWikiRepository->load($id);
      $data = $this->miniWikiRepository->buildData($entity);

      $cacheMetadata = $this->buildCacheMetadata(['wiki-page:' . $id]);
      $response = new ResourceResponse($data);
      $response->addCacheableDependency($cacheMetadata);
    }
    catch (
      InvalidPluginDefinitionException
      | PluginNotFoundException
      | EntityMalformedException $e
    ) {
      $errorMessage = sprintf(
        'Error loading a mini wki page with ID %d: %s',
        $id,
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

}
