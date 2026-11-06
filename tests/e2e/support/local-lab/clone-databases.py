import gzip
import json
import os
import shutil
import subprocess
from pathlib import Path

os.umask(0o077)
LAB = next(parent for parent in Path(__file__).resolve().parents if parent.name == '.e2e-lab')
STATE = LAB / 'state'
info = json.loads((STATE / 'database-private.json').read_text())

def defaults(name, options):
    def quote(value):
        return '"' + str(value).replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\r', '\\r') + '"'
    path = STATE / (name + '.cnf')
    values = {'user': options['username'], 'password': options.get('password', ''), 'host': options.get('host', 'localhost'), 'port': options.get('port', 3306)}
    if options.get('unix_socket'): values['socket'] = options['unix_socket']
    path.write_text('[client]\n' + ''.join(key + '=' + quote(value) + '\n' for key, value in values.items()))
    path.chmod(0o600)
    return path

source = info['source']
source_config = defaults('source', source)
test_config = defaults('test', dict(source, username=info['user'], password=info['password']))
common = ['mysqldump', '--defaults-extra-file=' + str(source_config), '--single-transaction', '--quick', '--skip-triggers', '--skip-comments', '--no-tablespaces', '--set-gtid-purged=OFF', '--default-character-set=utf8mb4']
schema = STATE / 'source-schema.sql'
data = STATE / 'source-data.sql.gz'
if not schema.exists():
    partial = schema.with_suffix('.partial')
    with partial.open('wb') as output:
        subprocess.run(common + ['--no-data', source['database']], stdout=output, check=True)
    partial.replace(schema)
    print('Source schema captured.', flush=True)
if not data.exists():
    partial = data.with_suffix('.partial')
    args = common + ['--no-create-info', '--skip-add-locks', '--hex-blob']
    args += ['--ignore-table=' + source['database'] + '.' + table for table in info['excludeData']]
    print('Capturing a consistent source data snapshot without caches/queues/sessions.', flush=True)
    with gzip.open(partial, 'wb', compresslevel=1) as output:
        process = subprocess.Popen(args + [source['database']], stdout=subprocess.PIPE)
        shutil.copyfileobj(process.stdout, output, length=1024 * 1024)
        process.stdout.close()
        if process.wait(): raise RuntimeError('Source dump failed')
    partial.replace(data)
    print('Source snapshot saved: ' + str(round(data.stat().st_size / 1048576)) + ' MiB compressed.', flush=True)
for kind, database in info['databases'].items():
    if not database.startswith('labdoo_e2e_'): raise RuntimeError('Unsafe target')
    marker = STATE / (kind + '-import-complete.json')
    if marker.exists():
        print(kind + ' database already imported.', flush=True)
        continue
    print('Importing ' + kind + ' with the isolated database account.', flush=True)
    command = ['mysql', '--defaults-extra-file=' + str(test_config), '--default-character-set=utf8mb4', database]
    with schema.open('rb') as input_file:
        subprocess.run(command, stdin=input_file, check=True)
    with gzip.open(data, 'rb') as input_file:
        process = subprocess.Popen(command, stdin=subprocess.PIPE)
        shutil.copyfileobj(input_file, process.stdin, length=1024 * 1024)
        process.stdin.close()
        if process.wait(): raise RuntimeError('Import failed: ' + kind)
    marker.write_text(json.dumps({'database': database, 'snapshotBytes': data.stat().st_size}))
    print(kind + ' database ready.', flush=True)
