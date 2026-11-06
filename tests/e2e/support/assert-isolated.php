<?php

$database = \Drupal::database()->getConnectionOptions()['database'];
$root = \Drupal::root();
if (
  !\Drupal\Core\Site\Settings::get('labdoo_e2e', FALSE)
  || !str_starts_with($database, 'labdoo_e2e_')
  || !str_contains($root, '/.e2e-lab/')
) {
  throw new RuntimeException('Refusing to run: this is not an isolated E2E site.');
}
echo 'Isolation verified: ' . \Drupal\Core\Site\Settings::get('labdoo_e2e_variant') . PHP_EOL;
