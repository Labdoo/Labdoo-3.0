<?php

declare(strict_types=1);

/**
 * @file
 * Check that web agent/model choices are restricted to server-side profiles.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/web/modules/custom/labdoo_common/src/Service/TranslationAgentRegistry.php';

use Drupal\Core\Site\Settings;
use Drupal\labdoo_common\Service\TranslationAgentRegistry;

$registry = new TranslationAgentRegistry(new Settings(['labdoo_translation_agents' => [
  'valid' => ['label' => 'Test translator', 'adapter' => 'custom', 'binary' => PHP_BINARY, 'models' => ['approved-model']],
  'relative' => ['label' => 'Invalid', 'adapter' => 'custom', 'binary' => 'php', 'models' => []],
  'directory' => ['label' => 'Invalid', 'adapter' => 'custom', 'binary' => '/tmp', 'models' => []],
  'bad_model' => ['label' => 'Invalid', 'adapter' => 'claude', 'binary' => PHP_BINARY, 'models' => [[]]],
  'bad_adapter' => ['label' => 'Invalid', 'adapter' => 'shell', 'binary' => PHP_BINARY, 'models' => []],
]]));
if (array_keys($registry->profiles()) !== ['valid']) { throw new RuntimeException('Invalid profiles must not be offered in the UI.'); }
if ($registry->options('valid', 'approved-model')['agent-binary'] !== PHP_BINARY) { throw new RuntimeException('The binary must come from server configuration.'); }
if ($registry->options('valid', '')['agent-model'] !== '') { throw new RuntimeException('The agent default must remain available.'); }
foreach ([['/bin/sh', ''], ['valid', 'unapproved-model'], ['valid', 'approved-model --extra-flag']] as [$id, $model]) {
  try { $registry->options($id, $model); throw new LogicException('Unconfigured web choice was accepted.'); }
  catch (InvalidArgumentException $e) {}
}
echo "Translation agent registry: 6 assertions passed.\n";
