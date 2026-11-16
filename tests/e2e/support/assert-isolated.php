<?php

$database = \Drupal::database()->getConnectionOptions()['database'];
$root = \Drupal::root();
$state = \Drupal\Core\Site\Settings::get('labdoo_e2e_state');
$currentCheckout = FALSE;
if (getenv('LABDOO_E2E_SITE') === '1' && $state === dirname($root) . '/tests/upgrade/.local/state') {
  $manifest = json_decode(file_get_contents($state . '/connection.json'), TRUE, 512, JSON_THROW_ON_ERROR);
  $currentCheckout = ($manifest['root'] ?? NULL) === dirname($root)
    && ($manifest['connection']['database'] ?? NULL) === $database
    && \Drupal::getContainer()->getParameter('site.path') === 'sites/labdoo_e2e';
}
if (
  !\Drupal\Core\Site\Settings::get('labdoo_e2e', FALSE)
  || !str_starts_with($database, 'labdoo_e2e_')
  || (!$currentCheckout && !str_contains($root, '/.e2e-lab/'))
) {
  throw new RuntimeException('Refusing to run: this is not an isolated E2E site.');
}
echo 'Isolation verified: ' . \Drupal\Core\Site\Settings::get('labdoo_e2e_variant') . PHP_EOL;
