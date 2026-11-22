<?php

namespace Drupal\labdoo_translations;

use Symfony\Component\Yaml\Yaml;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Portable lazy catalogs; validated file data may use a content-keyed cache.
 */
final class Catalog {
  // Bump this schema when changing validation rules or the cached
  // representation.
  private const CACHE_SCHEMA = 1;
  /**
   * The validated source-string catalog.
   */
  private array $source = [];
  /**
   * The validated portable catalogs loaded by language.
   */
  private array $languages = [];
  /**
   * The content hashes of catalog files read in this request.
   */
  private array $hashes = [];
  /**
   * The catalog validation errors collected during loading.
   */
  private array $errors = [];
  /**
   * Whether the source-string catalog has been loaded.
   */
  private bool $loaded = FALSE;

  public function __construct(private ?string $directory = NULL, private ?CacheBackendInterface $cache = NULL) {
    $this->directory ??= dirname(__DIR__) . '/catalog';
  }

  /**
   * Creates a stable catalog identity from source text and context.
   */
  public static function id(string $source, string $context = ''): string {
    return hash('sha256', $context . "\0" . $source);
  }

  /**
   * Reads a catalog JSON file while recording hashes and validation errors.
   */
  private function json(string $file): array {
    $path = $this->directory . '/' . $file;
    if (!is_file($path)) {
      $this->hashes[$file] = 'missing';
      return [];
    }
    try {
      $bytes = file_get_contents($path);
      if ($bytes === FALSE) {
        throw new \UnexpectedValueException();
      }
      // Bind cached data to the bytes actually parsed, including concurrent
      // edits.
      $this->hashes[$file] = hash('sha256', $bytes);
      $data = json_decode($bytes, TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($data)) {
        throw new \UnexpectedValueException();
      }
      return $data;
    }
    catch (\Throwable $e) {
      $this->errors[$file][] = 'invalid_json';
      return [];
    }
  }

  /**
   * Returns an immutable-content cache key without relying on file timestamps.
   */
  private function cacheId(string $file): ?string {
    if (!$this->cache || !empty($this->errors['source.json']) || !empty($this->errors[$file])) {
      return NULL;
    }
    foreach (array_unique(['source.json', $file]) as $name) {
      $path = $this->directory . '/' . $name;
      $this->hashes[$name] ??= is_file($path) ? hash_file('sha256', $path) : 'missing';
      if (!is_string($this->hashes[$name]) || strlen($this->hashes[$name]) !== 64) {
        return NULL;
      }
    }
    return 'labdoo_translations:validated:' . self::CACHE_SCHEMA . ':' . hash('sha256', $this->hashes['source.json'] . ':' . $file . ':' . $this->hashes[$file]);
  }

  /**
   * Stores only fully validated catalogs; native translations are never cached.
   */
  private function cacheValidated(string $file, array $data): void {
    if ($cid = $this->cacheId($file)) {
      $this->cache->set($cid, $data, time() + 86400);
    }
  }

  /**
   * Loads and validates the source-string catalog lazily.
   */
  public function strings(): array {
    if (!$this->loaded) {
      $this->loaded = TRUE;
      if (($cid = $this->cacheId('source.json')) && ($cached = $this->cache->get($cid))) {
        return $this->source = $cached->data;
      }
      foreach ($this->json('source.json')['strings'] ?? [] as $id => $entry) {
        if (!is_array($entry) || !is_string($entry['source'] ?? NULL) || !is_string($entry['context'] ?? '')) {
          $this->errors['source.json'][] = 'invalid_entry';
          continue;
        }
        $context = $entry['context'] ?? '';
        if (!hash_equals(self::id($entry['source'], $context), (string) $id)) {
          $this->errors['source.json'][] = 'source_id_mismatch';
          continue;
        }
        $this->source[$id] = array_replace($entry, ['context' => $context]);
      }
      $this->cacheValidated('source.json', $this->source);
    }
    return $this->source;
  }

  /**
   * Loads and validates one language's portable catalog.
   */
  private function language(string $language): array {
    if (!preg_match('/^[a-z][a-z0-9-]{0,19}$/D', $language)) {
      return [];
    }
    if (!array_key_exists($language, $this->languages)) {
      $source = $this->strings();
      if (($cid = $this->cacheId($language . '.json')) && ($cached = $this->cache->get($cid))) {
        return $this->languages[$language] = $cached->data;
      }
      $data = $this->json($language . '.json');
      $this->languages[$language] = [];
      if ($data && ($data['language'] ?? NULL) !== $language) {
        $this->errors[$language . '.json'][] = 'language_mismatch';
        return [];
      }
      foreach ($data['translations'] ?? [] as $id => $text) {
        if (!isset($source[$id])) {
          $this->errors[$language . '.json'][] = 'unknown_source_id';
          continue;
        }
        if (!is_string($text) || !self::validTranslation($source[$id]['source'], $text)) {
          $this->errors[$language . '.json'][] = 'invalid_translation';
          continue;
        }
        $this->languages[$language][$id] = $text;
      }
      $this->cacheValidated($language . '.json', $this->languages[$language]);
    }
    return $this->languages[$language];
  }

  /**
   * NULL means no translation, allowing Drupal's normal providers to continue.
   */
  public function lookup(string $language, string $source, string $context = ''): ?string {
    $strings = $this->strings();
    $id = self::id($source, $context);
    if (!isset($strings[$id]) || $strings[$id]['source'] !== $source || $strings[$id]['context'] !== $context) {
      return NULL;
    }
    return $this->language($language)[$id] ?? NULL;
  }

  /**
   * Keep placeholders, Drupal tokens, markup structure and link targets intact.
   */
  public static function compatiblePlurals(string $native, string $catalog): bool {
    $original = explode("\x03", $native);
    $reviewed = explode("\x03", $catalog);
    if (count($original) !== count($reviewed)) {
      return FALSE;
    }
    foreach ($reviewed as $index => $variant) {
      // A literal "1" is unsafe where this language also uses that form for
      // zero, twenty-one, etc. Keep the reviewed count placeholder in that
      // form.
      if (str_contains($variant, '@count') && !str_contains($original[$index], '@count')) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Validates translation structure and protected placeholders.
   */
  public static function validTranslation(string $source, string $translation): bool {
    if (trim($translation) === '' || !mb_check_encoding($source, 'UTF-8') || !mb_check_encoding($translation, 'UTF-8') || str_contains($translation, "\u{FFFD}") || preg_match('/[\x00-\x02\x04-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $translation)) {
      return FALSE;
    }
    // Webform's empty_message is a visible label enclosed in braces, not a
    // token.
    if ($source === '{Empty}') {
      return (bool) preg_match('/^\{[^{}<>\r\n]+\}$/uD', $translation);
    }
    // Drupal stores gettext variants separated by ETX. Languages can have a
    // different number of variants; compare each variant against source forms.
    if (str_contains($source, "\x03")) {
      $original = explode("\x03", $source);
      $numeric = !array_filter($original, static fn($form) => !preg_match('/^(?:[0-9]+|@count)$/D', $form));
      foreach (explode("\x03", $translation) as $variant) {
        if ($numeric && !preg_match('/^(?:[0-9]+|@count)$/D', $variant)) {
          return FALSE;
        }
        $valid = FALSE;
        foreach ($original as $form) {
          if (self::validTranslation($form, $variant)) {
            $valid = TRUE;
            break;
          }
        }
        if (!$valid) {
          return FALSE;
        }
      }
      return TRUE;
    }
    if (str_contains($translation, "\x03")) {
      return FALSE;
    }
    if (!self::sameYamlStructure($source, $translation)) {
      return FALSE;
    }
    $tokens = '~\{\{.*?\}\}|\{%.*?%\}|\{\#.*?\#\}|(?<![\w])[@!%:][A-Za-z_](?:[\w.-]*\w)?|%(?:\d+\$)?[-+0 \#]*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxX]|\[[A-Za-z_][\w.-]*(?::[^\]\n]+)+\]|\{[A-Za-z_][\w.-]*\}~us';
    preg_match_all($tokens, $source, $left);
    preg_match_all($tokens, $translation, $right);
    $left = $left[0];
    $right = $right[0];
    sort($left);
    sort($right);
    if ($left !== $right) {
      return FALSE;
    }
    return self::markup($source) === self::markup($translation);
  }

  /**
   * Preserves YAML keys and operational types when translating labels.
   */
  private static function sameYamlStructure(string $source, string $translation): bool {
    if (!str_contains($source, "\n") || !preg_match('/\A[^\r\n<>]+:/u', $source) || !class_exists(Yaml::class)) {
      return TRUE;
    }
    try {
      $original = Yaml::parse($source);
    }
    catch (\Throwable $e) {
      return TRUE;
    }
    if (!is_array($original)) {
      return TRUE;
    }
    try {
      $translated = Yaml::parse($translation);
    }
    catch (\Throwable $e) {
      return FALSE;
    }
    $shape = function ($value) use (&$shape) {
      if (!is_array($value)) {
        return get_debug_type($value);
      }
      $result = [];
      foreach ($value as $key => $child) {
        $result[get_debug_type($key) . ':' . $key] = $shape($child);
      }
      ksort($result);
      return $result;
    };
    return $shape($original) === $shape($translated);
  }

  /**
   * Normalizes markup without changing fixed attributes or structure.
   */
  private static function markup(string $text): array {
    if (!preg_match('/<(?:\s*\/?\s*[a-z][^<>]*>|!--)/iu', $text)) {
      return [];
    }
    $previous = libxml_use_internal_errors(TRUE);
    try {
      $document = new \DOMDocument('1.0', 'UTF-8');
      $loaded = $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $text . '</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
      $root = $document;
      if (!$loaded) {
        return [['invalid_markup']];
      }
      $out = [];
      $void = [
        'area',
        'base',
        'br',
        'col',
        'embed',
        'hr',
        'img',
        'input',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr',
      ];
      $walk = function ($node) use (&$walk, &$out, $void): void {
        foreach ($node->childNodes as $child) {
          if ($child instanceof \DOMComment) {
            $out[] = ['comment', $child->nodeValue];
            continue;
          }
          if (!$child instanceof \DOMElement) {
            continue;
          }
          $tag = strtolower($child->tagName);
          $attributes = [];
          foreach ($child->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (!in_array($name, ['alt', 'title', 'aria-label', 'placeholder'], TRUE)) {
              $attributes[$name] = $attribute->nodeValue;
            }
          }
          ksort($attributes);
          $out[] = ['start', $tag, $attributes];
          if (in_array($tag, ['script', 'style'], TRUE)) {
            $out[] = ['code', $child->textContent];
          }
          $walk($child);
          if (!in_array($tag, $void, TRUE)) {
            $out[] = ['end', $tag];
          }
        }
      };
      $walk($root);
      return $out;
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }
  }

  /**
   * Fingerprints the source and requested language catalog contents.
   */
  public function version(string $language): string {
    $this->strings();
    $this->language($language);
    return hash('sha256', ($this->hashes['source.json'] ?? 'missing') . ':' . ($this->hashes[$language . '.json'] ?? 'missing'));
  }

  /**
   * Reports catalog availability, translated counts and missing string IDs.
   */
  public function coverage(string $language): array {
    $source = $this->strings();
    $translated = $this->language($language);
    return [
      'language' => $language,
      'catalog_available' => ($this->hashes['source.json'] ?? 'missing') !== 'missing' && ($this->hashes[$language . '.json'] ?? 'missing') !== 'missing',
      'source_count' => count($source),
      'translated_count' => count($translated),
      'missing_ids' => array_values(array_diff(array_keys($source), array_keys($translated))),
    ];
  }

  /**
   * Returns the catalog validation errors collected during loading.
   */
  public function errors(): array {
    return $this->errors;
  }

  /**
   * Clears request-local catalogs, fingerprints and validation errors.
   */
  public function reset(): void {
    $this->source = $this->languages = $this->hashes = $this->errors = [];
    $this->loaded = FALSE;
  }

}
