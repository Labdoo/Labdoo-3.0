<?php

namespace Drupal\labdoo_translations;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\language\Config\LanguageConfigFactoryOverrideInterface;

/** Schema-declared translatable leaves only; never invokes config.factory. */
final class ConfigOverride implements ConfigFactoryOverrideInterface {
  public function __construct(private Catalog $catalog, private StorageInterface $storage, private TypedConfigManagerInterface $typed, private LanguageConfigFactoryOverrideInterface $languageOverride) {}

  public function language(): string { return $this->languageOverride->getLanguage()?->getId() ?? 'en'; }

  /** Inventory uses the same schema traversal as runtime; values stay internal. */
  public function leaves(string $name, array $data): array {
    $root = $this->typed->createFromNameAndData($name, $data); $leaves = [];
    $walk = function ($element, array $path = []) use (&$walk, &$leaves): void {
      if ($element instanceof \Traversable) {
        foreach ($element as $key => $child) { $walk($child, [...$path, $key]); }
        return;
      }
      $definition = $element->getDataDefinition(); $value = $element->getValue();
      // Even an incorrectly declared schema cannot translate credential/ID values.
      $key = strtolower((string) ($path ? $path[count($path) - 1] : ''));
      if (in_array($key, ['uuid', 'id', 'api_key', 'apikey', 'secret', 'secret_key', 'client_secret', 'password', 'pass', 'token', 'access_token', 'refresh_token', 'credentials'], TRUE)) { return; }
      if (($definition['translatable'] ?? FALSE) === TRUE && is_string($value) && trim($value) !== '') {
        $leaves[] = ['path' => $path, 'source' => $value, 'context' => (string) ($definition['translation context'] ?? ''), 'type' => $definition->getDataType()];
      }
    };
    $walk($root);
    return $leaves;
  }

  public function loadOverrides($names) {
    $language = $this->language(); $nativeStorage = $this->storage->createCollection('language.' . $language);
    $native = $nativeStorage->readMultiple($names); $out = [];
    foreach ($this->storage->readMultiple($names) as $name => $data) {
      $nativeData = $native[$name] ?? [];
      try { $leaves = $this->leaves($name, $data); }
      catch (\Exception $e) { continue; }
      foreach ($leaves as $leaf) {
        $text = $this->catalog->lookup($language, $leaf['source'], $leaf['context']);
        $exists = FALSE; $current = NestedArray::getValue($nativeData, $leaf['path'], $exists);
        $nativeValid = $exists && is_string($current) && $current !== $leaf['source'] && Catalog::validTranslation($leaf['source'], $current) && ($text === NULL || !str_contains($leaf['source'], "\x03") || Catalog::compatiblePlurals($current, $text));
        if ($nativeValid) { continue; }
        if ($text !== NULL && ($text !== $leaf['source'] || ($exists && $current !== $text))) { $out[$name] ??= []; NestedArray::setValue($out[$name], $leaf['path'], $text); }
      }
    }
    return $out;
  }

  public function getCacheSuffix() { return 'labdoo_translations:' . $this->language() . ':' . $this->catalog->version($this->language()); }
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) { return NULL; }
  public function getCacheableMetadata($name) { return (new CacheableMetadata())->setCacheContexts(['languages:language_interface', 'labdoo_translations'])->setCacheTags(['config:' . $name]); }
}
