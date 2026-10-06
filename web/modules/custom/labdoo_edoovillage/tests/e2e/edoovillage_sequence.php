<?php

/**
 * Runtime sequence check: set LABDOO_EXPECTED_NEXT_ID, then run with drush php:script.
 *
 * Uses the installed site's real node table and releases the sequence lock.
 */

$expected = getenv('LABDOO_EXPECTED_NEXT_ID');
if ($expected === FALSE || !ctype_digit($expected)) {
  throw new RuntimeException('Set LABDOO_EXPECTED_NEXT_ID to the next available edoovillage number.');
}

$manager = \Drupal::service('labdoo_edoovillage.sequence_manager');
try {
  $actual = $manager->get();
}
finally {
  $manager->commit();
}

if ($actual !== (int) $expected) {
  throw new RuntimeException("Expected edoovillage #$expected, got #$actual.");
}

echo "Next edoovillage number is #$actual.\n";
