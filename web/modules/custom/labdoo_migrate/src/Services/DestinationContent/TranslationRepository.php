<?php

namespace Drupal\labdoo_migrate\Services\DestinationContent;

use Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * The translation repository.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class TranslationRepository implements TranslationRepositoryInterface {

  use LoggerAwareTrait;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  private EntityRepositoryInterface $entityRepository;

  /**
   * Forces the translations to be created and attached to the main content.
   *
   * @var bool
   */
  private bool $createTranslations;

  /**
   * TranslationRepository constructor.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   The logger channel factory.
   * @param \Drupal\labdoo_migrate\Services\Config\ConfigurationManagerInterface $configurationManager
   *   The configuration manager.
   *
   * @throws \Exception
   */
  public function __construct(
    EntityRepositoryInterface $entityRepository,
    LoggerChannelFactoryInterface $loggerChannelFactory,
    ConfigurationManagerInterface $configurationManager
  ) {

    $this->entityRepository = $entityRepository;
    $this->setLogger($loggerChannelFactory->get('labdoo_migrate'));
    $contentTranslationConfig = $configurationManager
      ->getGlobalConfiguration()
      ->getContentTranslationConfig();
    $this->createTranslations = (bool) $contentTranslationConfig['create_translations'];
  }

  /**
   * {@inheritDoc}
   */
  public function isCreateTranslations(): bool {
    return $this->createTranslations;
  }

  /**
   * {@inheritDoc}
   */
  public function getEntityTranslation(
    EntityInterface $entity,
    string $langCode
  ) {

    return $entity->hasTranslation($langCode)
      ? $entity->getTranslation($langCode)
      : $entity->addTranslation($langCode);
  }

  /**
   * {@inheritDoc}
   */
  public function hasEntityTranslation(
    EntityInterface $entity,
    string $langCode
  ): bool {

    $translation = $this->entityRepository
      ->getTranslationFromContext($entity, $langCode);

    return $translation->language()->getId() === $langCode;
  }

}
