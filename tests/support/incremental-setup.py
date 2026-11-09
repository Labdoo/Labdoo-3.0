"""Build independent minimal Drupal destination and synthetic V2 DB, never overwrite a site."""
import json,os,subprocess,shutil,secrets
from pathlib import Path
os.umask(0o077)
ROOT=Path(__file__).resolve().parents[2]; LAB=ROOT.parents[1]; STATE=LAB/'state'
info=json.loads((STATE/'database-private.json').read_text()); source=info['source']
if source.get('host','localhost') not in ('localhost','127.0.0.1'): raise RuntimeError('Local MySQL required')
dest='labdoo_e2e_incremental_dest_20261109'; src='labdoo_e2e_incremental_source_20261109'
site=LAB/'incremental-site'
admin=['mysql','--defaults-extra-file='+str(STATE/'source.cnf')]
q=lambda v:"'"+str(v).replace('\\','\\\\').replace("'","''")+"'"
user=info['user']; readuser='labdoo_e2e_delta_reader'; password=secrets.token_hex(24)
marker=STATE/'incremental-fixtures-ready.json'
if marker.exists(): print('Independent fixtures already prepared');raise SystemExit
if site.exists(): raise RuntimeError('Refusing to overwrite an existing unmarked fixture site')
sql=''.join(f'CREATE DATABASE IF NOT EXISTS `{d}` CHARACTER SET utf8mb4; GRANT ALL ON `{d}`.* TO {q(user)}@\'localhost\';' for d in (dest,src,'labdoo_e2e_incremental_engine_20261109'))
sql+=f'CREATE USER IF NOT EXISTS {q(readuser)}@\'localhost\' IDENTIFIED BY {q(password)}; ALTER USER {q(readuser)}@\'localhost\' IDENTIFIED BY {q(password)}; GRANT SELECT ON `{src}`.* TO {q(readuser)}@\'localhost\';'
subprocess.run(admin,input=sql,text=True,check=True,stdout=subprocess.DEVNULL)
base=info['databases']['integration']
def stream_dump(args):
    dump=subprocess.Popen(['mysqldump','--defaults-extra-file='+str(STATE/'test.cnf'),'--single-transaction','--quick','--skip-triggers','--no-tablespaces','--set-gtid-purged=OFF']+args,stdout=subprocess.PIPE)
    imp=subprocess.run(admin+[dest],stdin=dump.stdout,check=True);dump.stdout.close()
    if dump.wait():raise RuntimeError('Fixture dump failed')
stream_dump(['--no-data',base]);stream_dump(['--no-create-info','--skip-add-locks',base,'config','key_value'])
# Only anonymous account; no real-user content is copied.
for table in ('users','users_field_data'):
    dump=subprocess.Popen(['mysqldump','--defaults-extra-file='+str(STATE/'test.cnf'),'--no-create-info','--skip-add-locks','--skip-triggers','--no-tablespaces','--set-gtid-purged=OFF','--where=uid=0',base,table],stdout=subprocess.PIPE)
    subprocess.run(admin+[dest],stdin=dump.stdout,check=True);dump.stdout.close()
    if dump.wait():raise RuntimeError('Anonymous fixture dump failed')
site.mkdir(exist_ok=True)
for relative in ['web/modules/contrib','web/themes/contrib','web/libraries','web/profiles/contrib']:
    origin=LAB/'integration'/relative;target=site/relative
    if origin.exists() and not target.exists():target.parent.mkdir(parents=True,exist_ok=True);target.symlink_to(origin,target_is_directory=True)
for name in ('composer.json','composer.lock'): shutil.copyfile(LAB/'integration'/name,site/name)
for relative in ['vendor','web/core','web/modules/custom','web/themes/custom','config']:
    origin=LAB/'integration'/relative
    if origin.exists():shutil.copytree(origin,site/relative,dirs_exist_ok=True,symlinks=True)
module=site/'web/modules/custom/labdoo_migrate'
shutil.rmtree(module);module.symlink_to(ROOT/'web/modules/custom/labdoo_migrate',target_is_directory=True)
for name in ['index.php','autoload.php']:
    shutil.copyfile(LAB/'integration/web'/name,site/'web'/name)
settings_dir=site/'web/sites/default';settings_dir.mkdir(parents=True,exist_ok=True);(settings_dir/'files').mkdir(exist_ok=True)
private=STATE/'incremental-private-files';private.mkdir(exist_ok=True)
conn=dict(source,database=dest,username=user,password=info['password'],prefix='');conn.pop('pdo',None)
for suffix,c in [('engine',dict(conn,database='labdoo_e2e_incremental_engine_20261109')),('dest',conn),('source-writer',dict(conn,database=src)),('source-reader',dict(conn,database=src,username=readuser,password=password))]:
    (STATE/('incremental-'+suffix+'-connection.json')).write_text(json.dumps(c))
settings=(LAB/'integration/web/sites/default/settings.php').read_text()
# Start from established safety settings, then explicitly override all connections.
settings+='\n$databases[\'default\'][\'default\'] = json_decode(file_get_contents('+q(str(STATE/'incremental-dest-connection.json'))+'), TRUE);\n'
settings+='$databases[\'external\'][\'default\'] = json_decode(file_get_contents('+q(str(STATE/'incremental-source-reader-connection.json'))+'), TRUE);\n'
settings+='$settings[\'file_private_path\'] = '+q(str(private))+';\n$settings[\'labdoo_e2e_variant\'] = \'incremental\';\n'
(settings_dir/'settings.php').write_text(settings)
subprocess.run(['python3',str(ROOT/'tests/support/incremental-drush.py'),'cache:rebuild'],cwd=ROOT,check=True)
marker.write_text(json.dumps({'source':src,'destination':dest,'site':str(site)}))
print('Independent minimal Drupal destination and SELECT-only synthetic source prepared')
