<?php

require __DIR__ . '/assert-isolated.php';
$factory = \Drupal::configFactory();
$factory->getEditable('mailsystem.settings')
  ->set('modules', [])
  ->set('defaults', ['sender' => 'test_mail_collector', 'formatter' => 'test_mail_collector'])
  ->save();
$factory->getEditable('system.mail')->set('interface', ['default' => 'test_mail_collector'])->save();
\Drupal::state()->set('labdoo_geocoding_disabled', FALSE);
\Drupal::state()->set('labdoo_migrate_is_running', FALSE);
$manager = \Drupal::service('plugin.manager.mail');
$plugin = $manager->getInstance(['module' => 'labdoo_notifications', 'key' => 'probe']);
if ($plugin instanceof \Drupal\mailsystem\Adapter) {
  $property = new ReflectionProperty($plugin, 'instanceSender');
  $sender = $property->getValue($plugin);
}
else { $sender = $plugin; }
if (!$sender instanceof \Drupal\Core\Mail\Plugin\Mail\TestMailCollector) {
  throw new RuntimeException('Mail transport is not isolated');
}
$result = \Drupal::service('geocoder')->geocode('E2E fixture address', ['googlemaps']);
if (!$result || $result->first()->getProvidedBy() !== 'labdoo-e2e') {
  throw new RuntimeException('Geocoder is not deterministic');
}
echo 'Mail transport and geocoder isolation verified.' . PHP_EOL;
