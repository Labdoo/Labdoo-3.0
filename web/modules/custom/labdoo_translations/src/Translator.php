<?php

namespace Drupal\labdoo_translations;

use Drupal\Core\StringTranslation\Translator\TranslatorInterface;

/**
 * Resolves interface strings through validated portable catalogs.
 */
final class Translator implements TranslatorInterface {
  /**
   * The request-local translated-string lookup cache.
   */
  private array $cache = [];
  /**
   * The catalog versions used by request-local translation lookups.
   */
  private array $versions = [];

  public function __construct(private Catalog $catalog, private ?object $nativeStorage = NULL) {}

  /**
   * {@inheritdoc}
   */
  public function getStringTranslation($langcode, $string, $context) {
    $version = $this->catalog->version($langcode);
    if (($this->versions[$langcode] ?? NULL) !== $version) {
      $this->cache[$langcode] = [];
      $this->versions[$langcode] = $version;
    }
    $id = Catalog::id($string, $context);
    if (array_key_exists($id, $this->cache[$langcode] ?? [])) {
      return $this->cache[$langcode][$id];
    }
    $text = $this->catalog->lookup($langcode, $string, $context);
    if ($text !== NULL && $this->nativeStorage) {
      $native = $this->nativeStorage->findTranslation([
        'language' => $langcode,
        'source' => $string,
        'context' => $context,
      ]);
      $value = $native?->getString();
      if (is_string($value) && $value !== $string && Catalog::validTranslation($string, $value) && (!str_contains($string, "\x03") || Catalog::compatiblePlurals($value, $text))) {
        $text = $value;
      }
    }
    return $this->cache[$langcode][$id] = $text ?? FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function reset() {
    $this->cache = $this->versions = [];
    $this->catalog->reset();
  }

}
