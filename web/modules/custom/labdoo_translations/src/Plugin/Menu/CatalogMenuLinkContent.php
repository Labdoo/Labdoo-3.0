<?php

declare(strict_types=1);

namespace Drupal\labdoo_translations\Plugin\Menu;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Language\LanguageInterface;
use Drupal\menu_link_content\Plugin\Menu\MenuLinkContent;

/** Portable text fallback for core content-menu links; never changes entities. */
class CatalogMenuLinkContent extends MenuLinkContent {

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    return $this->catalogText('title');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->catalogText('description');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['languages:language_interface', 'labdoo_translations']);
  }

  /**
   * {@inheritdoc}
   */
  public function updateLink(array $new_definition_values, $persist) {
    $definition = parent::updateLink($new_definition_values, $persist);
    // A normal user edit must not persist this runtime class into an external
    // link which would outlive this module when it is disabled/uninstalled.
    $definition['class'] = MenuLinkContent::class;
    return $definition;
  }

  /**
   * Use actual base text, even when legacy English links have a wrong langcode.
   *
   * Core caches a translated entity clone in the plugin. Resolve the interface
   * language explicitly on every call so that neither that clone nor a copied
   * English translation can mask an available portable catalog translation.
   */
  protected function catalogText(string $field): string {
    $entity = $this->getEntity();
    $base = $entity->getUntranslated();
    $source = (string) ($base->get($field)->value ?? '');
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
    if ($entity->hasTranslation($langcode)) {
      $native = (string) ($entity->getTranslation($langcode)->get($field)->value ?? '');
      if (trim($native) !== '' && trim($native) !== trim($source)) {
        return $native;
      }
    }
    if ($source === '') {
      return $source;
    }
    // No environment-specific entity ID, UUID, route or link URL enters the key.
    return \Drupal::service('labdoo_translations.catalog')->lookup($langcode, $source, '') ?? $source;
  }

}
