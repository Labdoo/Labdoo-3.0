<?php

declare(strict_types=1);

/**
 * @file
 * Exercises the actual command with isolated Locale data and a fake local CLI.
 */

require __DIR__ . '/assert-isolated.php';

use Drupal\Component\Gettext\PoStreamReader;
use Drupal\labdoo_common\Commands\TranslationCommands;
use Drupal\labdoo_common\Service\LocalTranslationAgent;
use Drupal\labdoo_common\Service\TranslationWorkflow;
use Drupal\labdoo_translations\Catalog;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

$directory = sys_get_temp_dir() . '/labdoo-po-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$binary = $directory . '/fake-agent';
file_put_contents($binary, <<<'PHP'
#!/usr/bin/env php
<?php
$prompt = stream_get_contents(STDIN);
$entries = json_decode(substr($prompt, strpos($prompt, "\n") + 1), TRUE, 512, JSON_THROW_ON_ERROR);
$response = [];
foreach ($entries as $entry) {
  $sources = (array) $entry['source'];
  $response[$entry['id']] = 'Traducido ' . $sources[min($entry['plural_index'], count($sources) - 1)];
}
echo json_encode($response, JSON_UNESCAPED_UNICODE);
PHP);
chmod($binary, 0700);
$storage = \Drupal::service('locale.storage');
$source = $storage->createString(['source' => 'Labdoo agent integration @name ' . bin2hex(random_bytes(5)), 'context' => 'agent-regression'])->save();
if (!class_exists(Catalog::class)) {
  require_once DRUPAL_ROOT . '/modules/custom/labdoo_translations/src/Catalog.php';
}
$reviewed = $storage->createString(['source' => 'Reviewed agent integration @name ' . bin2hex(random_bytes(5)), 'context' => 'agent-regression'])->save();
$id = Catalog::id($reviewed->source, 'agent-regression');
mkdir($directory . '/catalog', 0700);
file_put_contents($directory . '/catalog/source.json', json_encode(['strings' => [$id => ['source' => $reviewed->source, 'context' => 'agent-regression']]]));
file_put_contents($directory . '/catalog/es.json', json_encode(['language' => 'es', 'translations' => [$id => 'Revisado @name']]));
$catalog = new Catalog($directory . '/catalog');
// Concurrent browser renders may register new interface strings. Keep this
// command regression scoped to its owned Locale fixture inventory.
$workflow = new class(\Drupal::languageManager(), \Drupal::configFactory(), \Drupal::moduleHandler(), new LocalTranslationAgent(), $catalog) extends TranslationWorkflow {
  public function pending(string $language): array {
    return array_filter(parent::pending($language), static fn($item): bool => $item->getContext() === 'agent-regression');
  }
};
$command = new TranslationCommands(\Drupal::languageManager(), \Drupal::configFactory(), \Drupal::moduleHandler(), new LocalTranslationAgent(), $catalog, $workflow);
$command->setInput(new ArrayInput([]));
$command->setOutput(new BufferedOutput());
$options = ['langcodes' => 'es', 'output-dir' => $directory, 'agent' => 'claude', 'agent-binary' => $binary, 'batch-size' => 100];
$file = $directory . '/labdoo_custom.es.po';
try {
  $command->update($options + ['dry-run' => TRUE]);
  if (is_file($file)) { throw new RuntimeException('Dry run wrote a PO file.'); }
  $command->update($options);
  $reader = new PoStreamReader();
  $reader->setURI($file);
  $reader->open();
  $found = FALSE;
  while ($item = $reader->readItem()) {
    if ($item->getSource() === $reviewed->source) { throw new RuntimeException('A reviewed portable translation was unnecessarily exported.'); }
    if ($item->getSource() === $source->source && $item->getContext() === 'agent-regression') {
      $found = str_starts_with((string) $item->getTranslation(), 'Traducido') && str_contains($item->getTranslation(), '@name');
    }
  }
  $reader->close();
  if (!$found) { throw new RuntimeException('Selected agent did not translate the pending Locale string.'); }
  $before = hash_file('sha256', $file);
  $command->update($options);
  if (hash_file('sha256', $file) !== $before) { throw new RuntimeException('Repeated run changed existing translations.'); }
  file_put_contents($file, "\nmsgctxt \"agent-regression\"\nmsgid \"" . $reviewed->source . "\"\nmsgstr \"\"\n", FILE_APPEND);
  $command->update($options);
  if (!str_contains((string) file_get_contents($file), 'msgstr "Revisado @name"')) { throw new RuntimeException('Older PO entries did not reuse the reviewed catalog translation.'); }
  // Force a missing entry in the PO so the failed CLI is actually invoked.
  file_put_contents($file, "\nmsgctxt \"failure\"\nmsgid \"Pending @name\"\nmsgstr \"\"\n", FILE_APPEND);
  $before = hash_file('sha256', $file);
  file_put_contents($binary, "#!/usr/bin/env php\n<?php echo 'invalid JSON';");
  try {
    $command->update($options);
    throw new LogicException('Invalid agent response accepted.');
  }
  catch (RuntimeException $e) {
    if (hash_file('sha256', $file) !== $before) { throw new RuntimeException('Failed CLI modified the PO file.'); }
  }
  echo "translation_agent_integration=passed\n";
}
finally {
  $source->delete();
  $reviewed->delete();
  if (is_file($file)) { unlink($file); }
  unlink($binary);
  unlink($directory . '/catalog/source.json');
  unlink($directory . '/catalog/es.json');
  rmdir($directory . '/catalog');
  rmdir($directory);
}
