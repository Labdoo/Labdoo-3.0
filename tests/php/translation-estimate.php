<?php

declare(strict_types=1);

/**
 * @file
 * Database-free cost, workload and billing tests; rates are fictitious fixtures.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
foreach (['LocalTranslationAgent', 'TranslationAgentRegistry', 'TranslationWorkflow', 'TranslationJobs', 'TranslationEstimate'] as $class) {
  require dirname(__DIR__, 2) . '/web/modules/custom/labdoo_common/src/Service/' . $class . '.php';
}

use Drupal\Component\Gettext\PoHeader;
use Drupal\Component\Gettext\PoItem;
use Drupal\Core\Language\Language;
use Drupal\Core\Site\Settings;
use Drupal\labdoo_common\Service\LocalTranslationAgent;
use Drupal\labdoo_common\Service\TranslationAgentRegistry;
use Drupal\labdoo_common\Service\TranslationEstimate;
use Drupal\labdoo_common\Service\TranslationWorkflow;

$checks = 0;
function verify(bool $condition, string $message): void {
  global $checks;
  if (!$condition) { throw new RuntimeException($message); }
  $checks++;
}
$workflow = new class extends TranslationWorkflow {
  public array $items = [];
  public function __construct() { $this->catalog = NULL; }
  public function languages(array $requested = []): array {
    $all = ['es' => new Language(['id' => 'es']), 'fr' => new Language(['id' => 'fr'])];
    if (array_diff($requested, array_keys($all))) { throw new InvalidArgumentException('Unknown language'); }
    return $requested ? array_intersect_key($all, array_flip($requested)) : $all;
  }
  public function prepare(string $language, ?string $file = NULL): array {
    $header = new PoHeader($language);
    $header->setFromString("Plural-Forms: nplurals=2; plural=(n != 1);\n");
    return ['header' => $header, 'items' => $this->items];
  }
};
$single = new PoItem(); $single->setSource('Translate @name'); $single->setTranslation('');
$plural = new PoItem(); $plural->setSource(['One @count item', 'Many @count items']); $plural->setPlural(TRUE); $plural->setTranslation(['Already translated @count', '']);
$done = new PoItem(); $done->setSource('Done'); $done->setTranslation('Conservado');
$workflow->items = [$single, $plural, $done];
$rates = ['billing' => 'metered', 'currency' => 'USD', 'input_per_million' => 2, 'output_per_million' => 8, 'as_of' => '2026-11-19'];
$profiles = ['fixture' => ['label' => 'Fixture', 'adapter' => 'custom', 'binary' => PHP_BINARY, 'models' => ['model', 'subscription'], 'pricing' => ['model' => $rates, 'subscription' => ['billing' => 'subscription']]]];
$registry = new TranslationAgentRegistry(new Settings(['labdoo_translation_agents' => $profiles]));
$estimator = new TranslationEstimate($workflow, $registry, new LocalTranslationAgent());
$estimate = $estimator->calculate(['es', 'fr'], 'fixture', 'model');
verify($estimate['total']['entries'] === 4, 'Translated entries must not be charged again.');
verify($estimate['total']['forms'] === 4, 'Only missing plural forms must be counted.');
verify($estimate['total']['calls'] === 2, 'Calls must follow ten-entry job batches.');
verify($estimate['total']['input_max'] >= $estimate['total']['input_min'] && $estimate['total']['input_min'] > 0, 'Input range must include prompt overhead.');
verify($estimate['total']['output_max'] >= $estimate['total']['output_min'], 'Output range must allow translation expansion.');
$expected = ($estimate['total']['input_min'] * 2 + $estimate['total']['output_min'] * 8) / 1000000;
verify(abs($estimate['total']['cost_min'] - $expected) < 1e-12, 'Rates must be applied per million tokens.');
verify($estimate['fingerprint'] === $estimator->calculate(['es', 'fr'], 'fixture', 'model')['fingerprint'], 'Stable inventory must have a stable estimate fingerprint.');
verify($estimate['fingerprint'] !== $estimator->calculate(['es'], 'fixture', 'model')['fingerprint'], 'Changed target languages must invalidate review.');
$unknown = $estimator->calculate(['es'], 'fixture', '');
verify($unknown['pricing']['billing'] === 'unknown' && $unknown['total']['cost_max'] === NULL, 'Unpriced default models must not be reported as free.');
$subscription = $estimator->calculate(['es'], 'fixture', 'subscription');
verify($subscription['pricing']['billing'] === 'subscription' && $subscription['total']['cost_max'] === NULL, 'Subscription limits are not an API price or a zero charge.');
$single->setSource('Translate @user');
verify($estimate['fingerprint'] !== $estimator->calculate(['es', 'fr'], 'fixture', 'model')['fingerprint'], 'Same-size content changes must invalidate review.');
$pricedFingerprint = $estimator->calculate(['es'], 'fixture', 'model')['fingerprint'];
$profiles['fixture']['pricing']['model']['input_per_million'] = 9;
$other = new TranslationEstimate($workflow, new TranslationAgentRegistry(new Settings(['labdoo_translation_agents' => $profiles])), new LocalTranslationAgent());
verify($other->calculate(['es'], 'fixture', 'model')['fingerprint'] !== $pricedFingerprint, 'Changed rates must invalidate review.');
$profiles['fixture']['pricing']['model']['input_per_million'] = -1;
$invalid = new TranslationAgentRegistry(new Settings(['labdoo_translation_agents' => $profiles]));
verify($invalid->pricing('fixture', 'model')['billing'] === 'unknown', 'Invalid rates must not produce negative cost.');
$profiles['fixture']['pricing']['model']['input_per_million'] = 2;
new Settings(['labdoo_translation_agents' => $profiles]);
$workflow->items = [$done];
$empty = $estimator->calculate(['es'], 'fixture', 'model');
verify($empty['total']['calls'] === 0 && $empty['total']['cost_max'] === 0.0, 'A priced empty workload must not create requests.');
$workflow->items = array_fill(0, 11, $single);
verify($estimator->calculate(['es'], 'fixture', 'model')['total']['calls'] === 2, 'Batch prompt overhead must be counted separately.');
echo "Translation estimate: $checks assertions passed.\n";
