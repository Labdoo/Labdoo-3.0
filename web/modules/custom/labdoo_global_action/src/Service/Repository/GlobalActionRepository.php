<?php

namespace Drupal\labdoo_global_action\Service\Repository;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\labdoo_global_action\Exception\UnknownServiceException;
use Drupal\labdoo_global_action\Service\ActionGenerator\ActionGeneratorFactory;

/**
 * Global action repository.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class GlobalActionRepository {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * GlobalActionRepository constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerChannelFactory
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $loggerChannelFactory->get('labdoo_global_action');
  }

  /**
   * Creates the global action.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return void
   */
  public function createGlobalAction(EntityInterface $entity): void {
    try {
      $actionGenerator = ActionGeneratorFactory::get($entity->bundle());
    } catch (UnknownServiceException $e) {
      return;
    }

    try {
      $globalAction = $this->entityTypeManager
        ->getStorage('node')
        ->create([
          'type' => 'action',
          'uid' => $entity->getOwner()->id(),
        ]);
      $actionGenerator->generate($entity, $globalAction);
      if (!empty($globalAction->label())) {
        $globalAction->save();
      }
    } catch (
      InvalidPluginDefinitionException
      | EntityStorageException
      | PluginNotFoundException $e
    ) {
      $errorMessage = sprintf(
        'Error generating the global action for %s %d: %s',
        $entity->bundle(),
        $entity->id(),
        $e->getMessage()
      );
      $this->logger->error($errorMessage);
    }
  }

}
