<?php
/** Offline, validated transfer of versioned Labdoo translation catalogs. */
declare(strict_types=1);

$project = dirname(__DIR__);
// Options follow the action: php script.php export --file=/path/catalog.json.
$action = $argv[1] ?? '';
$arguments = array_slice($argv, 2);
$options = [];
foreach ($arguments as $argument) {
  if (!preg_match('/^--(catalog|file|autoload)=(.+)$/D', $argument, $match)) {
    fwrite(STDERR, "Use check|export|import --file=PATH [--catalog=DIR] [--autoload=PATH]\n"); exit(2);
  }
  $options[$match[1]] = $match[2];
}
$autoload = $options['autoload'] ?? $project . '/vendor/autoload.php';
if (!is_file($autoload)) { fwrite(STDERR, "Composer autoload is required.\n"); exit(2); }
require_once $autoload;
require_once $project . '/web/modules/custom/labdoo_translations/src/Catalog.php';
require_once $project . '/web/modules/custom/labdoo_translations/src/PluralPreparation.php';
use Drupal\labdoo_translations\Catalog;
use Drupal\labdoo_translations\PluralPreparation;

function decode(string $text): array {
  $value = json_decode($text, TRUE, 512, JSON_THROW_ON_ERROR);
  if (!is_array($value)) { throw new RuntimeException('Expected a JSON object.'); }
  return $value;
}

/** Validate every entry, including YAML with the same Symfony parser as Drupal. */
function validateCatalog(string $directory): array {
  (new PluralPreparation($directory))->canonical();
  $source = decode(file_get_contents($directory . '/source.json'));
  $languages = $source['languages'] ?? [];
  $expected = ['en','es','ar','ca','zh-hans','zh-hant','nl','fr','de','it','ja','pl','ru','sr','sw','uk','hy','bs','ro','tr'];
  $sorted = $languages; sort($sorted); sort($expected);
  if ($sorted !== $expected || !is_array($source['strings'] ?? NULL) || !$source['strings']) { throw new RuntimeException('Incomplete language/source inventory.'); }
  $catalog = new Catalog($directory);
  if (count($catalog->strings()) !== count($source['strings'])) { throw new RuntimeException('Invalid source identities.'); }
  $reasons = ['brand','unit','code','url','numeric','same_word','already_target_language','source_language'];
  foreach ($languages as $language) {
    $target = decode(file_get_contents($directory . '/' . $language . '.json'));
    $coverage = $catalog->coverage($language);
    if (!$coverage['catalog_available'] || $coverage['translated_count'] !== $coverage['source_count']) { throw new RuntimeException('Incomplete or invalid catalog: ' . $language); }
    foreach ($target['unchanged'] ?? [] as $id => $reason) {
      if (!in_array($reason, $reasons, TRUE) || !isset($source['strings'][$id]) || ($target['translations'][$id] ?? NULL) !== $source['strings'][$id]['source']) { throw new RuntimeException('Invalid unchanged metadata: ' . $language); }
    }
    foreach ($source['strings'] as $id => $entry) {
      if (($target['translations'][$id] ?? NULL) === $entry['source'] && !in_array($target['unchanged'][$id] ?? $entry['invariant'] ?? NULL, $reasons, TRUE)) { throw new RuntimeException('Unreviewed identical translation: ' . $language); }
    }
  }
  if ($catalog->errors()) { throw new RuntimeException('Catalog integrity errors.'); }
  return ['languages' => $languages, 'source_entries' => count($source['strings'])];
}

$directory = $options['catalog'] ?? $project . '/web/modules/custom/labdoo_translations/catalog';
$stage = NULL;
$exitCode = 0;
try {
  if (!in_array($action, ['check', 'export', 'import'], TRUE)) { throw new RuntimeException('Expected action check, export or import.'); }
  if ($action === 'import') {
    if (empty($options['file'])) { throw new RuntimeException('--file is required.'); }
    $bundle = decode(file_get_contents($options['file']));
    if (($bundle['format'] ?? NULL) !== 'labdoo-translations-v1' || !is_array($bundle['files'] ?? NULL)) { throw new RuntimeException('Unknown translation bundle format.'); }
    $source = decode($bundle['files']['source.json']['contents'] ?? '');
    $allowed = ['source.json'];
    foreach ($source['languages'] ?? [] as $language) {
      if (!is_string($language) || !preg_match('/^[a-z][a-z0-9-]{0,19}$/D', $language)) { throw new RuntimeException('Invalid language filename.'); }
      $allowed[] = $language . '.json';
    }
    $actual = array_keys($bundle['files']); sort($actual); sort($allowed);
    if ($actual !== $allowed) { throw new RuntimeException('Unexpected or missing bundle files.'); }
    if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) { throw new RuntimeException('Catalog destination must be a real directory.'); }
    $parent = dirname($directory);
    if (!is_dir($parent)) { throw new RuntimeException('Catalog parent directory must exist.'); }
    $stage = $parent . '/.catalog-import-' . bin2hex(random_bytes(8));
    if (!mkdir($stage, 0700)) { throw new RuntimeException('Cannot create staging directory.'); }
    foreach ($bundle['files'] as $name => $entry) {
      if (!is_string($entry['contents'] ?? NULL) || !hash_equals(hash('sha256', $entry['contents']), (string) ($entry['sha256'] ?? ''))) { throw new RuntimeException('Bundle checksum mismatch.'); }
      if (file_put_contents($stage . '/' . $name, $entry['contents']) === FALSE) { throw new RuntimeException('Cannot stage bundle.'); }
      chmod($stage . '/' . $name, 0644);
    }
    $report = validateCatalog($stage);
    $backup = is_dir($directory) ? $parent . '/catalog-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) : NULL;
    if ($backup !== NULL && !rename($directory, $backup)) { throw new RuntimeException('Cannot retain previous catalog.'); }
    if (!rename($stage, $directory)) {
      if ($backup !== NULL) { rename($backup, $directory); }
      throw new RuntimeException('Cannot install catalog; previous catalog restored.');
    }
    $stage = NULL; chmod($directory, 0755);
    $report += ['action' => 'import', 'backup_directory' => $backup, 'next_step' => 'Run drush labdoo-translations:prepare --apply and drush cr. No content entities are imported.'];
  }
  else {
    $report = validateCatalog($directory);
    $report['action'] = $action;
    if ($action === 'export') {
      if (empty($options['file'])) { throw new RuntimeException('--file is required.'); }
      $bundle = ['format' => 'labdoo-translations-v1', 'files' => []];
      foreach (['source.json', ...array_map(fn($language) => $language . '.json', $report['languages'])] as $name) {
        $contents = file_get_contents($directory . '/' . $name);
        $bundle['files'][$name] = ['sha256' => hash('sha256', $contents), 'contents' => $contents];
      }
      // Exclusive creation protects an existing export from accidental overwrite.
      $handle = fopen($options['file'], 'x');
      if (!$handle) { throw new RuntimeException('Export destination already exists or cannot be created.'); }
      try {
        $encoded = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($handle, $encoded) !== strlen($encoded)) { throw new RuntimeException('Incomplete export write.'); }
      }
      finally { fclose($handle); }
    }
  }
  echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
}
catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); $exitCode = 1; }
finally {
  if ($stage !== NULL && is_dir($stage)) { foreach (glob($stage . '/*') as $file) { unlink($file); } rmdir($stage); }
}
exit($exitCode);
