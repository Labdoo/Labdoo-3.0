<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Controller;

use Drupal\Core\Ajax\AjaxResponse;

/**
 * Loads search dependencies using Drupal's asset and AJAX attachment pipeline.
 */
final class SearchAssetsController {

  /**
   * Returns public code assets, respecting the caller's loaded libraries.
   */
  public function assets(): AjaxResponse {
    $response = new AjaxResponse();
    $response->setAttachments(['library' => ['labdoo_next/search_enhanced']]);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    return $response;
  }

}
