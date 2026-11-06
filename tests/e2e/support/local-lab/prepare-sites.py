import json
import os
import shutil
from pathlib import Path

os.umask(0o077)
LAB = next(parent for parent in Path(__file__).resolve().parents if parent.name == '.e2e-lab')
SOURCE = LAB.parent
info = json.loads((LAB / 'state/database-private.json').read_text())
for kind, database in info['databases'].items():
    root = LAB / kind
    for relative in ['vendor', 'web/core', 'web/modules/contrib', 'web/themes/contrib', 'web/profiles/contrib', 'web/libraries']:
        target, origin = root / relative, SOURCE / relative
        if target.is_symlink():
            raise RuntimeError('Dependency symlinks must be replaced by independent copies: ' + str(target))
        if origin.exists():
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copytree(origin, target, dirs_exist_ok=True)
    web = root / 'web'
    for name in ['index.php', 'autoload.php', 'robots.txt']:
        if not (web / name).exists(): shutil.copyfile(SOURCE / 'web' / name, web / name)
    site = web / 'sites/default'
    site.mkdir(parents=True, exist_ok=True)
    files = site / 'files'
    if not files.exists():
        shutil.copytree(SOURCE / 'web/sites/default/files', files)
        for child in ['php', 'css', 'js']:
            if (files / child).exists(): shutil.rmtree(files / child)
    (LAB / 'state' / (kind + '-private-files')).mkdir(exist_ok=True)
    connection = dict(info['source'], database=database, username=info['user'], password=info['password'])
    connection.pop('pdo', None)
    private = {'connection': connection, 'salt': info['hashSalt']}
    private_file = LAB / 'state' / (kind + '-connection.json')
    private_file.write_text(json.dumps(private))
    private_file.chmod(0o600)
    config = '''<?php
$e2e = json_decode(file_get_contents('%s'), TRUE, 512, JSON_THROW_ON_ERROR);
$databases['default']['default'] = $e2e['connection'];
$settings['hash_salt'] = $e2e['salt'];
$settings['trusted_host_patterns'] = ['^127\\.0\\.0\\.1$', '^localhost$'];
$settings['config_sync_directory'] = dirname($app_root) . '/config/sync';
$settings['file_private_path'] = '%s';
$settings['file_chmod_directory'] = 0770;
$settings['file_chmod_file'] = 0660;
$settings['labdoo_e2e'] = TRUE;
$settings['labdoo_e2e_state'] = '%s';
$settings['labdoo_e2e_variant'] = '%s';
$config['automated_cron.settings']['interval'] = 0;
$config['geocoder.settings']['geocoder_presave_disabled'] = TRUE;
$config['geofield_map.settings']['gmap_api_key'] = '';
$config['system.mail']['interface'] = ['default' => 'test_mail_collector'];
$config['mailsystem.settings']['modules'] = [];
$config['mailsystem.settings']['defaults'] = ['sender' => 'test_mail_collector', 'formatter' => 'test_mail_collector'];
$config['captcha.captcha_point.user_login_form']['status'] = FALSE;
$config['captcha.captcha_point.user_register_form']['status'] = FALSE;
$config['honeypot.settings']['time_limit'] = 0;
$config['honeypot.settings']['protect_all_forms'] = FALSE;
$config['honeypot.settings']['form_settings'] = [];
''' % (private_file, LAB / 'state' / (kind + '-private-files'), LAB / 'state', kind)
    config += "\n$config['system.performance']['css']['preprocess'] = FALSE;\n$config['system.performance']['js']['preprocess'] = FALSE;\n"
    config += "$GLOBALS['conf']['container_service_providers']['LabdooE2eLast'] = 'Drupal\\labdoo_e2e\\LabdooE2eServiceProvider';\n"
    external = LAB / 'state/external-connection.json'
    if external.exists():
        config += "$databases['external']['default'] = json_decode(file_get_contents(" + repr(str(external)) + "), TRUE, 512, JSON_THROW_ON_ERROR);\n"
    settings = site / 'settings.php'
    if settings.exists() and 'labdoo_e2e_variant' in settings.read_text():
        print('Keeping existing isolated settings: ' + kind, flush=True)
    else:
        settings.write_text(config)
        settings.chmod(0o600)
    harness = web / 'modules/custom/labdoo_e2e'
    if not harness.exists(): harness.symlink_to(LAB / 'integration/tests/e2e/support/labdoo_e2e', target_is_directory=True)
    print('Prepared isolated site: ' + kind, flush=True)
