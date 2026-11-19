<?php

declare(strict_types=1);

namespace Drupal\labdoo_e2e;

use Drupal\Core\Site\Settings;
use Drupal\labdoo_common\Service\TranslationWorkflow;

/** Limits agent tests to owned Locale fixtures in the isolated laboratory. */
final class FixtureTranslationWorkflow extends TranslationWorkflow {

  public function pending(string $language): array {
    $items = parent::pending($language);
    $path = Settings::get('labdoo_e2e_state') . '/translation-ui.json';
    $data = is_file($path) ? json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR) : [];
    $context = $data['context'] ?? NULL;
    return $context ? array_filter($items, static fn($item): bool => $item->getContext() === $context) : $items;
  }

}
