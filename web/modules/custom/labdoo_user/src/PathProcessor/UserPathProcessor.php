<?php

namespace Drupal\labdoo_user\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\PathProcessor\OutboundPathProcessorInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Symfony\Component\HttpFoundation\Request;

/**
 * Processes user paths to use usernames instead of user IDs.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class UserPathProcessor implements InboundPathProcessorInterface, OutboundPathProcessorInterface {

  /**
   * Paths that should be processed by this path processor.
   *
   * @var array
   */
  protected $processedPaths = [
    'dashboard',
    'metrics',
    'roles',
  ];

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    // Pattern to match /user/{username}/{subpath} paths
    // Note: Language prefix is already stripped by the language path processor
    if (preg_match('|^/user/([^/]+)/([^/]+)$|', $path, $matches)) {
      $username = $matches[1];
      $subpath = $matches[2];

      // Only process specific subpaths (dashboard, metrics, roles)
      if (!in_array($subpath, $this->processedPaths)) {
        return $path;
      }

      // Skip numeric IDs (already processed)
      if (is_numeric($username)) {
        return $path;
      }

      // Try to load user by username
      try {
        $users = \Drupal::entityTypeManager()
          ->getStorage('user')
          ->loadByProperties(['name' => $username]);

        if ($user = reset($users)) {
          // Replace username with user ID
          return '/user/' . $user->id() . '/' . $subpath;
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('labdoo_user')->error('Error processing user path: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $path;
  }

  /**
   * {@inheritdoc}
   */
  public function processOutbound($path, &$options = [], Request $request = NULL, BubbleableMetadata $bubbleable_metadata = NULL) {
    // Pattern to match /user/{user_id}/{subpath} paths
    if (preg_match('|^/user/(\d+)/([^/]+)$|', $path, $matches)) {
      $user_id = $matches[1];
      $subpath = $matches[2];

      // Only process specific subpaths (dashboard, metrics, roles)
      if (!in_array($subpath, $this->processedPaths)) {
        return $path;
      }

      // Load user and replace ID with username
      try {
        $user = \Drupal::entityTypeManager()
          ->getStorage('user')
          ->load($user_id);

        if ($user && !$user->isAnonymous()) {
          $username = $user->getAccountName();
          return '/user/' . $username . '/' . $subpath;
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('labdoo_user')->error('Error processing outbound user path: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $path;
  }

}