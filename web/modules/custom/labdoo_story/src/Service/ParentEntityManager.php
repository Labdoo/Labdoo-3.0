<?php

namespace Drupal\labdoo_story\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for resolving and retrieving the parent entity.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class ParentEntityManager {

  /**
   * The valid parent bundles.
   */
  private const VALID_BUNDLES = [
    'hub',
    'edoovillage',
  ];

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructor method.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory service.
   *
   * @return void
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MessengerInterface $messenger,
    LoggerChannelFactoryInterface $loggerChannelFactory
  ) {
    $this->logger = $loggerChannelFactory->get('labdoo_story');
  }

  /**
   * Resolves and retrieves the parent entity based on the current request.
   *
   * This method checks for a valid parent entity ID from the request query
   * and attempts to load the entity using the entity type manager. It ensures
   * the parent entity exists and belongs to a valid bundle. If the entity does
   * not meet these conditions or an exception is encountered during the
   * process, it logs the error, displays an error message to the user, and
   * redirects to the homepage.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The resolved parent entity.
   *
   * @throws \Exception
   */
  public function resolveParentEntity(): EntityInterface {
    $parentId = $this->requestStack
      ->getCurrentRequest()
      ->query
      ->get('parent_id');

    try {
      $parentEntity = $this->entityTypeManager
        ->getStorage('node')
        ->load($parentId);
      if (!$parentEntity) {
        throw new \Exception('The parent entity could not be found.');
      }

      $isValidBundle = in_array(
        $parentEntity->bundle(),
        self::VALID_BUNDLES
      );
      if (!$isValidBundle) {
        throw new \Exception('The parent entity is not valid.');
      }

      return $parentEntity;
    }
    catch (InvalidPluginDefinitionException | PluginNotFoundException $e) {
      $message = 'The story cannot be created. The parent entity could not be found.';
      $this->messenger->addError($message, TRUE);
      $this->logger->error($message);

      throw new \Exception($message);
    }
    catch (\Exception $e) {
      $message = $e->getMessage();
      $this->messenger->addError($message, TRUE);
      $this->logger->error($message);

      throw $e;
    }
  }

}
