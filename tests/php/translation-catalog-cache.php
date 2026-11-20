<?php

declare(strict_types=1);

/** Exercises warm catalog reuse without stale files or lost native overrides. */
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/web/modules/custom/labdoo_translations/src/Catalog.php';
require dirname(__DIR__, 2) . '/web/modules/custom/labdoo_translations/src/Translator.php';

use Drupal\Component\Datetime\Time;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\labdoo_translations\Catalog;
use Drupal\labdoo_translations\Translator;
use Symfony\Component\HttpFoundation\RequestStack;

$cache = new class(new Time(new RequestStack())) extends MemoryBackend {
  public int $hits = 0;
  public function get($cid, $allow_invalid = FALSE) {
    $item = parent::get($cid, $allow_invalid);
    if ($item) { $this->hits++; }
    return $item;
  }
};
$directory = sys_get_temp_dir() . '/labdoo-catalog-cache-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$id = Catalog::id('Hello @name', 'greeting');
$source = ['strings' => [$id => ['source' => 'Hello @name', 'context' => 'greeting']]];
$language = ['language' => 'fr', 'translations' => [$id => 'Bonjour @name']];
$write = static function (string $file, array $data) use ($directory): void {
  file_put_contents($directory . '/' . $file, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
};
$expect = static function (bool $condition, string $message): void {
  if (!$condition) { throw new RuntimeException($message); }
};
try {
  $write('source.json', $source); $write('fr.json', $language);
  $first = new Catalog($directory, $cache);
  $expect($first->lookup('fr', 'Hello @name', 'greeting') === 'Bonjour @name', 'Cold catalog translation failed.');
  $second = new Catalog($directory, $cache);
  $expect($second->lookup('fr', 'Hello @name', 'greeting') === 'Bonjour @name', 'Warm catalog translation failed.');
  $expect($cache->hits > 0, 'A fresh catalog instance did not reuse validated data.');
  $expect($second->lookup('fr', 'Hello @name', 'other') === NULL, 'Contexts must remain distinct.');
  $stamp = filemtime($directory . '/fr.json'); $size = filesize($directory . '/fr.json');
  $version = $second->version('fr');
  $language['translations'][$id] = 'Bonsoir @name'; $write('fr.json', $language); touch($directory . '/fr.json', $stamp); clearstatcache();
  $expect(filesize($directory . '/fr.json') === $size, 'The replacement must exercise identical-size edits.');
  $second->reset();
  $expect($second->lookup('fr', 'Hello @name', 'greeting') === 'Bonsoir @name', 'Same-size/same-mtime file changes must invalidate cached data.');
  $expect($second->version('fr') !== $version, 'Content changes must update the catalog version.');
  $native = new class {
    public string $text = 'Salut @name';
    public function findTranslation(array $conditions): object {
      return new class($this->text) {
        public function __construct(private string $text) {}
        public function getString(): string { return $this->text; }
      };
    }
  };
  $translator = new Translator(new Catalog($directory, $cache), $native);
  $expect($translator->getStringTranslation('fr', 'Hello @name', 'greeting') === 'Salut @name', 'Warm portable data must preserve native translations.');
  $native->text = 'Coucou @name'; $translator->reset();
  $expect($translator->getStringTranslation('fr', 'Hello @name', 'greeting') === 'Coucou @name', 'Native updates must survive translator reset.');
  $source['strings'] = [Catalog::id('Other @name', 'greeting') => ['source' => 'Other @name', 'context' => 'greeting']];
  $write('source.json', $source);
  $changed = new Catalog($directory, $cache);
  $expect($changed->lookup('fr', 'Hello @name', 'greeting') === NULL, 'A changed source catalog must not reuse the old language map.');
  $changed->coverage('fr');
  $expect(in_array('unknown_source_id', $changed->errors()['fr.json'] ?? [], TRUE), 'Source mismatches must remain diagnosable.');
  $source['strings'] = [$id => ['source' => 'Hello @name', 'context' => 'greeting']]; $write('source.json', $source);
  $language['translations'][$id] = 'Missing placeholder'; $write('fr.json', $language);
  $invalid = new Catalog($directory, $cache);
  $expect($invalid->lookup('fr', 'Hello @name', 'greeting') === NULL, 'Invalid placeholders must not bypass validation on a warm backend.');
  $expect(in_array('invalid_translation', $invalid->errors()['fr.json'] ?? [], TRUE), 'Invalid translations must retain their diagnostic.');
  file_put_contents($directory . '/fr.json', '{');
  $broken = new Catalog($directory, $cache);
  $expect($broken->lookup('fr', 'Hello @name', 'greeting') === NULL && in_array('invalid_json', $broken->errors()['fr.json'] ?? [], TRUE), 'Malformed replacements must not serve stale translations.');
  unlink($directory . '/fr.json');
  $missing = new Catalog($directory, $cache);
  $expect($missing->lookup('fr', 'Hello @name', 'greeting') === NULL && !$missing->coverage('fr')['catalog_available'], 'Deleted language catalogs must become unavailable.');
  echo "Catalog reuse, content fingerprints, contexts, validation and native overrides passed.\n";
}
finally {
  foreach (['source.json', 'fr.json'] as $file) { if (is_file($directory . '/' . $file)) { unlink($directory . '/' . $file); } }
  rmdir($directory);
}
