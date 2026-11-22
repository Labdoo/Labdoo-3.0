<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Service;

use Drupal\Core\Site\Settings;

/**
 * Server-controlled agent profiles; web requests cannot supply executables. */
class TranslationAgentRegistry {

  public function __construct(private Settings $settings) {}

  /**
   * Returns only structurally valid profiles configured by the operator. */
  public function profiles(): array {
    $profiles = $this->settings->get('labdoo_translation_agents', []);
    $valid = [];
    foreach (is_array($profiles) ? $profiles : [] as $id => $profile) {
      if (is_string($id) && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id) && is_array($profile)
        && is_string($profile['label'] ?? NULL) && is_string($profile['binary'] ?? NULL)
        && str_starts_with($profile['binary'], '/') && is_file($profile['binary']) && is_executable($profile['binary'])
        && in_array($profile['adapter'] ?? '', ['claude', 'codex', 'gemini', 'custom'], TRUE)
        && is_array($profile['models'] ?? [])
        && !array_filter($profile['models'] ?? [], static fn($model): bool => !is_string($model) || strlen($model) > 200)) {
        $valid[$id] = $profile;
      }
    }
    return $valid;
  }

  /**
   * Resolves an allowed profile and model to immutable process arguments. */
  public function options(string $id, string $model): array {
    $profile = $this->profiles()[$id] ?? NULL;
    if (!$profile || ($model !== '' && !in_array($model, $profile['models'] ?? [], TRUE))) {
      throw new \InvalidArgumentException('The selected agent or model is not available.');
    }
    return [
      'agent' => $profile['adapter'],
      'agent-binary' => $profile['binary'],
      'agent-model' => $model,
      'batch-size' => 100,
      'agent-timeout' => 120,
    ];
  }

  /**
   * Returns optional operator-maintained rates; missing prices never mean free. */
  public function pricing(string $id, string $model): array {
    $this->options($id, $model);
    $rate = $this->profiles()[$id]['pricing'][$model] ?? [];
    if (!is_array($rate)) {
      return ['billing' => 'unknown'];
    }
    if (($rate['billing'] ?? '') === 'subscription') {
      return ['billing' => 'subscription'];
    }
    if (($rate['billing'] ?? '') !== 'metered' || !is_string($rate['currency'] ?? NULL) || !preg_match('/^[A-Z]{3}$/D', $rate['currency'])) {
      return ['billing' => 'unknown'];
    }
    foreach (['input_per_million', 'output_per_million'] as $key) {
      if (!is_numeric($rate[$key] ?? NULL) || !is_finite((float) $rate[$key]) || $rate[$key] < 0) {
        return ['billing' => 'unknown'];
      }
    }
    return [
      'billing' => 'metered',
      'currency' => $rate['currency'],
      'input_per_million' => (float) $rate['input_per_million'],
      'output_per_million' => (float) $rate['output_per_million'],
      'as_of' => is_string($rate['as_of'] ?? NULL) ? $rate['as_of'] : '',
      'source' => is_string($rate['source'] ?? NULL) ? $rate['source'] : '',
    ];
  }

}
