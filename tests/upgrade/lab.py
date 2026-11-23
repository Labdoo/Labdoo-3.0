"""Loopback regression lab for this checkout, with an isolated Drupal site and database."""
import argparse, hashlib, gzip, json, os, re, shutil, subprocess, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
LAB = ROOT / 'tests/upgrade/.local'
STATE = LAB / 'state'
PORT = int(os.environ.get('LABDOO_E2E_PORT', '18411'))
if PORT not in [18411, 18421]: raise RuntimeError('Use an approved isolated loopback port')
DB = 'labdoo_e2e_' + hashlib.sha256(str(ROOT).encode()).hexdigest()[:12]
DB_USER = DB
os.umask(0o077)
ENV = dict(os.environ, LABDOO_E2E_SITE='1', XDEBUG_MODE='off', AWS_EC2_METADATA_DISABLED='true', AWS_CONFIG_FILE='/dev/null', AWS_SHARED_CREDENTIALS_FILE='/dev/null', HTTP_PROXY='http://127.0.0.1:9', HTTPS_PROXY='http://127.0.0.1:9', http_proxy='http://127.0.0.1:9', https_proxy='http://127.0.0.1:9', NO_PROXY='127.0.0.1,localhost')
def run(args, **kw):
    return subprocess.run(args, cwd=ROOT, env=ENV, check=True, **kw)
def drush(*args):
    if not (STATE/'connection.json').exists(): raise RuntimeError('Prepare the isolated database first')
    manifest = json.loads((STATE/'connection.json').read_text())
    if manifest.get('root') != str(ROOT): raise RuntimeError('The lab must be prepared for this checkout; do not reuse another worktree state')
    command = [str(ROOT/'vendor/bin/drush'),'--root='+str(ROOT/'web'),'--uri=http://127.0.0.1:'+str(PORT)]
    run(command+['php:script',str(ROOT/'tests/e2e/support/assert-isolated.php')], stdout=subprocess.DEVNULL)
    return run(command+list(args))
def write_drush_wrapper(tools):
    # The existing browser helpers pass the variant before the Drush command.
    # This checkout has one writable variant; never silently map baseline to it.
    script = "import runpy,sys\nif len(sys.argv)<3 or sys.argv[1]!='integration': raise RuntimeError('Expected the integration variant and a Drush command')\nm=runpy.run_path("+repr(str(Path(__file__).resolve()))+")\nm['drush'](*sys.argv[2:])\n"
    (tools/'drush.py').write_text(script)

def prepare():
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
    sql = f"CREATE DATABASE `{DB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; CREATE USER '{DB_USER}'@'localhost' IDENTIFIED BY '{password}'; GRANT ALL ON `{DB}`.* TO '{DB_USER}'@'localhost';"
    run(['mysql','--defaults-extra-file='+str(admin)], input=sql.encode())
    cnf=STATE/'clone.cnf'
    cnf.write_text('[client]\nuser='+DB_USER+'\npassword='+password+'\nhost=localhost\n')
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
    local=dict(connection,database=DB,username=DB_USER,password=password)
    local.pop('pdo',None)
    (STATE/'connection.json').write_text(json.dumps({'connection':local,'salt':os.urandom(32).hex(),'root':str(ROOT)}))
    site=ROOT/'web/sites/labdoo_e2e'; site.mkdir(parents=True,exist_ok=True)
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
    write_drush_wrapper(tools)
    drush('en','labdoo_e2e','-y')
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
