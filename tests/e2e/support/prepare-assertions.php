<?php

require __DIR__ . '/harden.php';
$state = \Drupal\Core\Site\Settings::get('labdoo_e2e_state');
$kind = \Drupal\Core\Site\Settings::get('labdoo_e2e_variant');
$fixtures = json_decode(file_get_contents($state . '/' . $kind . '-fixtures.json'), TRUE, 512, JSON_THROW_ON_ERROR);
\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load($fixtures['accounts']['admin']['id']));
$school = \Drupal\node\Entity\Node::load($fixtures['nodes']['School']['id']);
$school->skip_geocoding_enqueue = TRUE;
$school->skip_recompute_enqueue = TRUE;
$school->set('field_edoo_additional_notif_em', ['e2e-private@example.invalid'])->save();
echo 'Prepared populated private fields for visibility assertions.' . PHP_EOL;
