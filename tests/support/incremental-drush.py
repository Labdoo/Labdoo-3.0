import os,subprocess,sys,json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];LAB=ROOT.parents[1];site=LAB/'incremental-site'
c=json.loads((LAB/'state/incremental-dest-connection.json').read_text())
if c['database']!='labdoo_e2e_incremental_dest_20261109' or c.get('host') not in ('localhost','127.0.0.1'):raise RuntimeError('Unsafe fixture')
env=dict(os.environ,AWS_EC2_METADATA_DISABLED='true',AWS_CONFIG_FILE='/dev/null',AWS_SHARED_CREDENTIALS_FILE='/dev/null')
for key in ('HTTP_PROXY','HTTPS_PROXY','http_proxy','https_proxy'):env[key]='http://127.0.0.1:9'
env['NO_PROXY']=env['no_proxy']='localhost,127.0.0.1'
cmd=['php','-d','xdebug.mode=off',str(site/'vendor/bin/drush'),'--root='+str(site/'web'),'--uri=http://127.0.0.1:18383']
raise SystemExit(subprocess.run(cmd+sys.argv[1:],cwd=site,env=env).returncode)
