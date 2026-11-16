"""Private, loopback-only Drupal 10 → 11 laboratory. No production writes."""
import argparse, gzip, json, os, re, shutil, subprocess, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
LAB = ROOT / 'tests/upgrade/.local'
STATE = LAB / 'state'
PORT = 18411
DB = 'labdoo_e2e_drupal11_20261113'
os.umask(0o077)
ENV = dict(os.environ, XDEBUG_MODE='off', AWS_EC2_METADATA_DISABLED='true', AWS_CONFIG_FILE='/dev/null', AWS_SHARED_CREDENTIALS_FILE='/dev/null', HTTP_PROXY='http://127.0.0.1:9', HTTPS_PROXY='http://127.0.0.1:9', http_proxy='http://127.0.0.1:9', https_proxy='http://127.0.0.1:9', NO_PROXY='127.0.0.1,localhost')
def run(args, **kw):
    return subprocess.run(args, cwd=ROOT, env=ENV, check=True, **kw)
def drush(*args):
    if not (STATE/'connection.json').exists(): raise RuntimeError('Prepare the isolated database first')
    command = [str(ROOT/'vendor/bin/drush'),'--root='+str(ROOT/'web'),'--uri=http://127.0.0.1:'+str(PORT)]
    run(command+['php:script',str(ROOT/'tests/e2e/support/assert-isolated.php')], stdout=subprocess.DEVNULL)
    return run(command+list(args))
def prepare():
    if '/.e2e-lab/' not in str(ROOT): raise RuntimeError('Use a separate .e2e-lab worktree')
    for relative in ['vendor','web/core','web/modules/contrib','web/themes/contrib']:
        if (ROOT/relative).is_symlink(): raise RuntimeError('Dependencies must be independent copies: '+relative)
    if (STATE/'connection.json').exists(): raise RuntimeError('Lab already exists; refusing to overwrite it')
    source = Path(os.environ['LABDOO_SOURCE_JSON']).resolve()
    admin = Path(os.environ['LABDOO_ADMIN_CNF']).resolve()
    info = json.loads(source.read_text())
    connection = info.get('connection', info.get('source', info))
    if connection.get('host','localhost') not in ['localhost','127.0.0.1']: raise RuntimeError('Only a local source is supported')
    STATE.mkdir(parents=True, exist_ok=True)
    password = os.urandom(24).hex()
    sql = f"CREATE DATABASE `{DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; CREATE USER 'labdoo_d11'@'localhost' IDENTIFIED BY '{password}'; GRANT ALL ON `{DB}`.* TO 'labdoo_d11'@'localhost';"
    run(['mysql','--defaults-extra-file='+str(admin)], input=sql.encode())
    cnf=STATE/'clone.cnf'
    cnf.write_text('[client]\nuser=labdoo_d11\npassword='+password+'\nhost=localhost\n')
    source_cnf=STATE/'source.cnf'
    def quote(v): return '"'+str(v).replace('\\','\\\\').replace('"','\\"')+'"'
    source_cnf.write_text('[client]\n'+''.join(k+'='+quote(v)+'\n' for k,v in {'user':connection['username'],'password':connection.get('password',''),'host':connection.get('host','localhost'),'port':connection.get('port',3306)}.items()))
    source_db=connection['database']
    query=run(['mysql','--defaults-extra-file='+str(source_cnf),'-N',source_db,'-e','SHOW TABLES'],capture_output=True).stdout.decode().splitlines()
    excludes=[t for t in query if t.startswith(('cache','queue','sessions','watchdog','flood','batch','search_api_db_'))]
    dump=['mysqldump','--defaults-extra-file='+str(source_cnf),'--single-transaction','--quick','--no-tablespaces','--set-gtid-purged=OFF','--skip-triggers','--hex-blob']
    with (STATE/'snapshot.sql').open('wb') as f:
        run(dump+['--no-data',source_db],stdout=f)
        run(dump+['--no-create-info']+['--ignore-table='+source_db+'.'+t for t in excludes]+[source_db],stdout=f)
    # One transaction avoids fsync for each dump INSERT.
    with (STATE/'snapshot.sql').open('rb') as f:
        process=subprocess.Popen(['mysql','--defaults-extra-file='+str(cnf),DB],stdin=subprocess.PIPE,env=ENV)
        process.stdin.write(b'SET autocommit=0; SET foreign_key_checks=0;\n')
        shutil.copyfileobj(f,process.stdin)
        process.stdin.write(b'\nCOMMIT;\n');process.stdin.close()
        if process.wait(): raise RuntimeError('Clone import failed')
    local=dict(connection,database=DB,username='labdoo_d11',password=password)
    local.pop('pdo',None)
    (STATE/'connection.json').write_text(json.dumps({'connection':local,'salt':os.urandom(32).hex()}))
    site=ROOT/'web/sites/default'; site.mkdir(parents=True,exist_ok=True)
    # No original settings are loaded: the clone cannot fall back to the source.
    (site/'settings.local.php').write_text("""<?php
$d = json_decode(file_get_contents(__DIR__ . '/../../../tests/upgrade/.local/state/connection.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$databases['default']['default'] = $d['connection'];
$settings['hash_salt'] = $d['salt'];
$settings['trusted_host_patterns'] = ['^127\\\\.0\\\\.0\\\\.1$', '^localhost$'];
$settings['config_sync_directory'] = dirname($app_root) . '/config/sync';
$settings['file_private_path'] = dirname($app_root) . '/tests/upgrade/.local/private';
$settings['labdoo_e2e'] = TRUE;
$settings['labdoo_e2e_variant'] = 'integration';
$settings['labdoo_e2e_state'] = dirname($app_root) . '/tests/upgrade/.local/state';
$GLOBALS['conf']['container_service_providers']['LabdooE2eLast'] = 'Drupal\\labdoo_e2e\\LabdooE2eServiceProvider';
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
""")
    harness=ROOT/'web/modules/custom/labdoo_e2e'
    if not harness.exists(): harness.symlink_to(ROOT/'tests/e2e/support/labdoo_e2e',target_is_directory=True)
    # Copies only the small harness fixture; imported content never needs source write access.
    (site/'files').mkdir(exist_ok=True)
    if os.environ.get('LABDOO_SOURCE_FILES'):
        origin=Path(os.environ['LABDOO_SOURCE_FILES']).resolve()
        shutil.copytree(origin,site/'files',dirs_exist_ok=True,ignore=lambda directory,names:[n for n in names if Path(directory)==origin and n in ['php','css','js']])
    (LAB/'private').mkdir(exist_ok=True)
    shutil.copy2(ROOT/'tests/e2e/support/cards.json',STATE/'cards.json')
    tools=LAB/'tools'; tools.mkdir(exist_ok=True)
    (tools/'drush.py').write_text("import runpy,sys\nsys.argv.pop(1)\nm=runpy.run_path("+repr(str(Path(__file__).resolve()))+")\nm['drush'](*sys.argv[1:])\n")
    run([str(ROOT/'vendor/bin/drush'),'en','labdoo_e2e','-y'])
    drush('updatedb','-y')
    drush('cr')
    run([sys.executable,str(ROOT/'tests/upgrade/configure.py')])
    drush('php:script',str(ROOT/'tests/e2e/support/seed.php'))
    drush('php:script',str(ROOT/'tests/upgrade/prepare.php'))
    drush('php:script',str(ROOT/'tests/upgrade/inventory.php'))
    run([sys.executable,str(ROOT/'tests/upgrade/fixtures.py')])
    print('Prepared isolated clone at http://127.0.0.1:'+str(PORT))
def serve():
    drush('php:script',str(ROOT/'tests/e2e/support/harden.php'))
    env=dict(ENV,LABDOO_E2E_WEBROOT=str(ROOT/'web'),PHP_CLI_SERVER_WORKERS='4')
    os.chdir(ROOT/'web')
    os.execvpe('php',['php','-d','xdebug.mode=off','-d','sendmail_path=/bin/true','-S','127.0.0.1:'+str(PORT),'-t',str(ROOT/'web'),str(ROOT/'tests/e2e/support/local-lab/router.php')],env)
if __name__=='__main__':
    p=argparse.ArgumentParser(); p.add_argument('command',choices=['prepare','serve','drush']); a,extra=p.parse_known_args()
    if a.command=='prepare': prepare()
    elif a.command=='serve': serve()
    else: drush(*extra)
