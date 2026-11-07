import json
import os
import secrets
import subprocess
from pathlib import Path

os.umask(0o077)
lab = next(parent for parent in Path(__file__).resolve().parents if parent.name == '.e2e-lab')
state = lab / 'state'
source = json.loads((state / 'external-connection.json').read_text())
if source.get('host') not in ['127.0.0.1', 'localhost'] or str(source.get('port')) != '3317':
    raise RuntimeError('Expected the local V2 Docker connection')
path = state / 'v2-import-private.json'
if path.exists():
    info = json.loads(path.read_text())
else:
    info = {'database': 'labdoo_e2e_v2_20261107', 'import_user': 'labdoo_e2e_v2_import', 'read_user': 'labdoo_e2e_v2_read', 'import_password': secrets.token_hex(24), 'read_password': secrets.token_hex(24)}
    path.write_text(json.dumps(info, indent=2))
    path.chmod(0o600)
if info['database'] != 'labdoo_e2e_v2_20261107': raise RuntimeError('Unexpected target')
def defaults(name, user, password, database=None):
    def quote(s): return '"' + str(s).replace('\\','\\\\').replace('"','\\"').replace('\n','\\n').replace('\r','\\r') + '"'
    values = {'host':source['host'], 'port':source['port'], 'user':user, 'password':password, 'default-character-set':'utf8mb4'}
    if database: values['database'] = database
    p = state / name
    p.write_text('[client]\n' + ''.join(k+'='+quote(v)+'\n' for k,v in values.items()))
    p.chmod(0o600)
    return p
admin = defaults('v2-admin.cnf', source['username'], source['password'])
sql = ['CREATE DATABASE IF NOT EXISTS `'+info['database']+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;']
for role, grant in [('import','ALL PRIVILEGES'), ('read','SELECT')]:
    user,password = info[role+'_user'],info[role+'_password']
    if user != 'labdoo_e2e_v2_' + role or len(password) != 48 or any(c not in '0123456789abcdef' for c in password): raise RuntimeError('Unsafe account')
    sql += ["CREATE USER IF NOT EXISTS '"+user+"'@'%' IDENTIFIED BY '"+password+"';", "GRANT "+grant+" ON `"+info['database']+"`.* TO '"+user+"'@'%';"]
subprocess.run(['mysql','--defaults-extra-file='+str(admin)], input='\n'.join(sql), text=True, check=True, capture_output=True)
defaults('v2-import.cnf', info['import_user'], info['import_password'], info['database'])
defaults('v2-reader.cnf', info['read_user'], info['read_password'], info['database'])
connection = dict(source, database=info['database'], username=info['read_user'], password=info['read_password'])
connection['init_commands'] = {'labdoo_e2e_readonly':'SET SESSION TRANSACTION READ ONLY'}
readonly = state / 'v2-readonly-connection.json'
readonly.write_text(json.dumps(connection, indent=2)); readonly.chmod(0o600)
print('Prepared isolated V2 database, restricted importer and SELECT-only reader.')
