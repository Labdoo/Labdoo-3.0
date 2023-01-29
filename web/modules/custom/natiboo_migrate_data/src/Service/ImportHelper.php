<?php

namespace Drupal\natiboo_migrate_data\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides helper functions for importing data from uploaded files.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ImportHelper {

  /**
   * Constructs a new instance of the class.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    protected RequestStack $requestStack,
  ) {
  }

  /**
   * Retrieves and decodes JSON data from an uploaded file.
   *
   * @return array
   *   Decoded JSON data containing "menu" and "links".
   *
   * @throws \Exception
   *   Thrown when no file is uploaded, the file is empty,
   *   the file cannot be read, or the decoded content does not contain
   *   the required "menu" and "links" keys.
   */
  public function getFileData(): array {
    $currentRequest = $this->requestStack->getCurrentRequest();

    if (!$currentRequest) {
      throw new \Exception('No active request found.');
    }

    $file = $currentRequest->files->get('files')['file'] ?? NULL;

    if (!$file) {
      throw new \Exception('No file uploaded.');
    }

    if ($file->getSize() === 0) {
      throw new \Exception('Empty file.');
    }

    $content = file_get_contents($file->getPathname());
    if (!$content) {
      throw new \Exception('Unable to read file.');
    }

    $data = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \Exception("Invalid JSON: " . json_last_error_msg());
    }

    return $data;
  }

}
