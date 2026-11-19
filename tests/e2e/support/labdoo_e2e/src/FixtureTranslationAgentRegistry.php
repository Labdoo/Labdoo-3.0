<?php

declare(strict_types=1);

namespace Drupal\labdoo_e2e;

use Drupal\Core\Site\Settings;
use Drupal\labdoo_common\Service\TranslationAgentRegistry;

/** Supplies only the isolated browser suite's private fake executable. */
final class FixtureTranslationAgentRegistry extends TranslationAgentRegistry {

  public function profiles(): array {
    $file = Settings::get('labdoo_e2e_state') . '/translation-ui.json';
    return is_file($file) ? json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR)['profiles'] : parent::profiles();
  }

}
