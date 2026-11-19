<?php

declare(strict_types=1);

/**
 * @file
 * Local CLI contract tests. No external provider or Drupal database is used.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/web/modules/custom/labdoo_common/src/Service/LocalTranslationAgent.php';

use Drupal\Component\Gettext\PoItem;
use Drupal\labdoo_common\Service\LocalTranslationAgent;

$directory = sys_get_temp_dir() . '/labdoo-agent-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$binary = $directory . '/fake-agent';
file_put_contents($binary, <<<'PHP'
#!/usr/bin/env php
<?php
$prompt = stream_get_contents(STDIN);
$entries = json_decode(substr($prompt, strpos($prompt, "\n") + 1), TRUE, 512, JSON_THROW_ON_ERROR);
$response = [];
foreach ($entries as $entry) {
  $source = (array) $entry['source'];
  $response[$entry['id']] = 'Traducido ' . $source[min($entry['plural_index'], count($source) - 1)];
}
$model = array_search('--model', $argv, TRUE);
$mode = $model === FALSE ? '' : $argv[$model + 1];
if ($mode === 'missing') { array_pop($response); }
if ($mode === 'markup') { $response[array_key_first($response)] = 'Broken'; }
if ($mode === 'markup-order') { $response[array_key_first($response)] = str_replace('<strong>friend</strong>', '</strong>friend<strong>', $response[array_key_first($response)]); }
if ($mode === 'twig') { $response[array_key_first($response)] = str_replace('{{ value }}', '{{ changed }}', $response[array_key_first($response)]); }
if ($mode === 'legacy') { $response[array_key_first($response)] = str_replace('!legacy', '!changed', $response[array_key_first($response)]); }
if ($mode === 'timeout') { sleep(3); }
if ($mode === 'failure') { fwrite(STDERR, 'PRIVATE_DIAGNOSTIC'); exit(7); }
$json = $mode === 'invalid' ? 'invalid' : json_encode($response, JSON_UNESCAPED_UNICODE);
$output = array_search('--output-last-message', $argv, TRUE);
if ($output !== FALSE) { file_put_contents($argv[$output + 1], $json); }
elseif (in_array('json', $argv, TRUE)) { echo json_encode(['response' => $json]); }
else { echo $json; }
PHP);
chmod($binary, 0700);
$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
  if (!$condition) { throw new RuntimeException($message); }
  $assertions++;
};
$agent = new LocalTranslationAgent();
$item = new PoItem();
$item->setSource('Hello @name !legacy {{ value }} <strong>friend</strong> https://labdoo.org');
$item->setContext('greeting');
$plural = new PoItem();
$plural->setSource(['One @count item', 'Many @count items']);
$plural->setPlural(TRUE);
$plural->setTranslation(['Existing @count translation', '']);
try {
  foreach (['claude', 'codex', 'gemini', 'custom'] as $provider) {
    $translated = $agent->translate([$item, $plural], 'es', 3, $provider, $binary, '', 1);
    $check(str_starts_with($translated[0]->getTranslation(), 'Traducido'), $provider . ': must decode the real CLI output format.');
    $check($translated[1]->getTranslation()[0] === 'Existing @count translation', 'Must preserve existing translations.');
    $check(count($translated[1]->getTranslation()) === 3, 'Must translate every target plural form.');
    $check(!$item->getTranslation(), 'Source PO objects must remain unchanged.');
  }
  foreach (['missing', 'markup', 'markup-order', 'twig', 'legacy', 'invalid', 'failure', 'timeout'] as $mode) {
    try {
      $agent->translate([$item], 'es', 2, 'claude', $binary, $mode, 20, 1);
      throw new LogicException('Invalid response was accepted: ' . $mode);
    }
    catch (RuntimeException $e) {
      $check(!str_contains($e->getMessage(), 'PRIVATE_DIAGNOSTIC'), 'Must not expose CLI diagnostics.');
    }
  }
  foreach ([['unknown', 20, 180], ['claude', 0, 180], ['codex', 20, 0], ['custom', 20, 180]] as $options) {
    try { $agent->validate(...$options); throw new LogicException('Invalid options accepted.'); }
    catch (InvalidArgumentException $e) { $assertions++; }
  }
  echo "Local translation agent: $assertions assertions passed.\n";
}
finally {
  unlink($binary);
  rmdir($directory);
}
