<?php

namespace Drupal\labdoo_translations;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\language\Config\LanguageConfigFactoryOverrideInterface;

/**
 * Varies cached output by the active portable translation catalog.
 */
final class CatalogCacheContext implements CacheContextInterface {

  public function __construct(private Catalog $catalog, private LanguageConfigFactoryOverrideInterface $languageOverride) {}

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return new TranslatableMarkup('Labdoo translation catalog');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    $language = $this->languageOverride->getLanguage()?->getId() ?? 'en';
    return $language . ':' . $this->catalog->version($language);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata() {
    return new CacheableMetadata();
  }

}
