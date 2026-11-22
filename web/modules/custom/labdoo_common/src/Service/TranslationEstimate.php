<?php

declare(strict_types=1);

namespace Drupal\labdoo_common\Service;

/**
 * Estimates application payloads locally; never launches an agent or writes PO.
 */
final class TranslationEstimate {

  public function __construct(
    private TranslationWorkflow $workflow,
    private TranslationAgentRegistry $agents,
    private LocalTranslationAgent $agent,
  ) {}

  /**
   * Binds a reviewed estimate to the selection, inventory and configured rates.
   */
  public function calculate(array $languages, string $profile, string $model): array {
    $targets = $this->workflow->languages($languages);
    if (!$languages || count($targets) !== count(array_unique($languages))) {
      throw new \InvalidArgumentException('Select target languages.');
    }
    $pricing = $this->agents->pricing($profile, $model);
    $selection = ['languages' => array_keys($targets), 'agent' => $profile, 'model' => $model];
    $hash = hash_init('sha256');
    hash_update($hash, serialize([$selection, $pricing, $this->agents->options($profile, $model)]));
    $rows = [];
    $total = [
      'entries' => 0,
      'forms' => 0,
      'calls' => 0,
      'input_min' => 0,
      'input_max' => 0,
      'output_min' => 0,
      'output_max' => 0,
    ];
    foreach ($targets as $language => $definition) {
      $snapshot = $this->workflow->prepare($language);
      $items = $this->workflow->translationItems($snapshot['items'], $language);
      $rule = $snapshot['header']->getPluralForms();
      $plurals = preg_match('/nplurals\s*=\s*(\d+)/', $rule, $match) ? (int) $match[1] : 2;
      $row = array_fill_keys(array_keys($total), 0);
      hash_update($hash, serialize([$language, $rule]));
      foreach ($items as $item) {
        hash_update($hash, serialize([$item->getContext(), $item->getSource(), $item->getTranslation()]));
      }
      foreach (array_chunk($items, TranslationJobs::BATCH) as $batch) {
        $requests = $this->agent->requests($batch, $plurals);
        if (!$requests) {
          continue;
        }
        $row['calls']++;
        $row['forms'] += count($requests);
        $row['entries'] += count(array_unique(array_map(static fn(array $request): string => explode(':', $request['id'])[0], $requests)));
        $inputBytes = strlen($this->agent->prompt($requests, $language, $rule));
        $response = [];
        foreach ($requests as $request) {
          $sources = (array) $request['source'];
          $response[$request['id']] = $sources[min($request['plural_index'], count($sources) - 1)];
        }
        $outputBytes = strlen(json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        // A documented heuristic, not a tokenizer, quotation or spending cap.
        $row['input_min'] += (int) ceil($inputBytes / 4);
        $row['input_max'] += (int) ceil($inputBytes / 2);
        $row['output_min'] += (int) ceil($outputBytes / 4);
        $row['output_max'] += $outputBytes;
      }
      foreach ($total as $key => $value) {
        $total[$key] += $row[$key];
      }
      $rows[$language] = $this->cost($row, $pricing);
    }
    return [
      'selection' => $selection,
      'pricing' => $pricing,
      'rows' => $rows,
      'total' => $this->cost($total, $pricing),
      'fingerprint' => hash_final($hash),
      'created' => time(),
    ];
  }

  /**
   * Converts bounded token estimates into configured metered cost ranges.
   */
  private function cost(array $usage, array $pricing): array {
    $usage['cost_min'] = $usage['cost_max'] = NULL;
    if ($pricing['billing'] === 'metered') {
      foreach (['min', 'max'] as $bound) {
        $cost = ($usage['input_' . $bound] * $pricing['input_per_million'] + $usage['output_' . $bound] * $pricing['output_per_million']) / 1000000;
        if (!is_finite($cost)) {
          throw new \InvalidArgumentException('Configured translation rates are too large.');
        }
        $usage['cost_' . $bound] = $cost;
      }
    }
    return $usage;
  }

}
