<?php

declare(strict_types=1);

// Never include the default site's settings or fall back to its connection.
if (getenv('LABDOO_E2E_SITE') !== '1') {
  throw new RuntimeException('The E2E site requires the dedicated local runner.');
}
$labdoo_e2e_root = dirname($app_root);
$labdoo_e2e_state = $labdoo_e2e_root . '/tests/upgrade/.local/state';
$d = json_decode(file_get_contents($labdoo_e2e_state . '/connection.json'), TRUE, 512, JSON_THROW_ON_ERROR);
if (
  ($d['root'] ?? NULL) !== $labdoo_e2e_root
  || !str_starts_with($d['connection']['database'] ?? '', 'labdoo_e2e_')
  || !in_array($d['connection']['host'] ?? '', ['localhost', '127.0.0.1'], TRUE)
  || ($d['connection']['username'] ?? '') !== 'labdoo_e2e_' . substr(hash('sha256', $labdoo_e2e_root), 0, 12)
) {
  throw new RuntimeException('Refusing an unprepared or non-local E2E connection.');
}
$databases['default']['default'] = $d['connection'];
$settings['hash_salt'] = $d['salt'];
$settings['trusted_host_patterns'] = ['^127\\.0\\.0\\.1$', '^localhost$'];
$settings['config_sync_directory'] = $labdoo_e2e_root . '/config/sync';
$settings['file_public_path'] = 'sites/labdoo_e2e/files';
$settings['file_private_path'] = $labdoo_e2e_root . '/tests/upgrade/.local/private';
$settings['labdoo_e2e'] = TRUE;
$settings['labdoo_e2e_variant'] = 'integration';
$settings['labdoo_e2e_state'] = $labdoo_e2e_state;
$class_loader->addPsr4('Drupal\\labdoo_e2e\\', $labdoo_e2e_root . '/tests/e2e/support/labdoo_e2e/src');
$GLOBALS['conf']['container_service_providers']['LabdooE2eLast'] = 'Drupal\labdoo_e2e\LabdooE2eServiceProvider';
$config['automated_cron.settings']['interval'] = 0;
$config['system.mail']['interface'] = ['default' => 'test_mail_collector'];
$config['mailsystem.settings']['modules'] = [];
$config['mailsystem.settings']['defaults'] = ['sender' => 'test_mail_collector', 'formatter' => 'test_mail_collector'];
$config['honeypot.settings']['time_limit'] = 0;
$config['honeypot.settings']['protect_all_forms'] = FALSE;
$config['honeypot.settings']['form_settings'] = [];
$config['captcha.captcha_point.user_login_form']['status'] = FALSE;
$config['captcha.captcha_point.user_register_form']['status'] = FALSE;
$config['geocoder.settings']['geocoder_presave_disabled'] = TRUE;
