<?php

/**
 * @file
 * Verifies observed dependency return types without saving any entities.
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;

require __DIR__ . '/assert-isolated.php';

if (User::load(2147483000) !== NULL
  || Webform::load('labdoo_e2e_missing_contract') !== NULL) {
  throw new RuntimeException('The missing-reference fixtures are unavailable.');
}
$node = Node::create(['type' => 'dootronic', 'uid' => 2147483000]);
// Storage can hydrate a submission whose source configuration is unavailable.
// Calling create() would require a present form and invoke its handlers.
$submission = new WebformSubmission([
  'webform_id' => 'labdoo_e2e_missing_contract',
], 'webform_submission');
if ($node->getOwner() !== NULL || $submission->getWebform() !== NULL) {
  throw new RuntimeException('Installed relation contracts changed; review the typing corrections.');
}
$query = \Drupal::database()->select('node', 'n');
if ($query !== $query->forUpdate()) {
  throw new RuntimeException('SELECT forUpdate no longer preserves the receiver.');
}
$reader = new \Drupal\Component\Gettext\PoStreamReader();
$reader->setURI(DRUPAL_ROOT . '/modules/custom/labdoo_quotes/translations/labdoo_quotes.es.po');
$reader->open();
try {
  $count = 0;
  while ($reader->readItem() !== NULL) {
    if (++$count > 10000) {
      throw new RuntimeException('PO stream did not reach its bounded end.');
    }
  }
  if ($count === 0 || $reader->readItem() !== NULL) {
    throw new RuntimeException('Installed PO stream end-of-file contract changed.');
  }
}
finally {
  $reader->close();
}
$overrides = \Drupal::service('language.config_factory_override');
$language = $overrides->getLanguage();
try {
  $overrides->setLanguage(NULL);
  if ($overrides->getLanguage() !== NULL) {
    throw new RuntimeException('Installed configuration override language contract changed.');
  }
}
finally {
  $overrides->setLanguage($language);
}
echo 'NULLABILITY_JSON:' . json_encode([
  'missing_owner' => TRUE,
  'missing_source_form' => TRUE,
  'fluent_select' => TRUE,
  'po_end_of_file' => TRUE,
  'missing_override_language' => TRUE,
], JSON_THROW_ON_ERROR);
