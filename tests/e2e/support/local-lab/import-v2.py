import hashlib
import json
import os
import subprocess
import time
from pathlib import Path

os.umask(0o077)
lab=next(parent for parent in Path(__file__).resolve().parents if parent.name == '.e2e-lab')
state=lab/'state'
source=Path(os.environ.get('LABDOO_E2E_V2_DUMP', '/var/www/html/labdoo2/labdoo.sql'))
marker=state/'v2-import-complete.json'
if marker.exists():
    metadata=json.loads(marker.read_text())
    if metadata['dump_bytes']!=source.stat().st_size:raise RuntimeError('Dump changed since import')
    print('V2 import already complete.');raise SystemExit(0)
config=state/'v2-import.cnf'
identity=subprocess.check_output(['mysql','--defaults-extra-file='+str(config),'--batch','--skip-column-names','-e',"SELECT DATABASE(),SUBSTRING_INDEX(CURRENT_USER(),'@',1)"],text=True).strip().split('\t')
if identity != ['labdoo_e2e_v2_20261107','labdoo_e2e_v2_import']:
    raise RuntimeError('Refusing an import outside the isolated V2 database/account')
probe=subprocess.check_output(['mysql','--defaults-extra-file='+str(config),'--batch','--skip-column-names','-e','SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()'],text=True).strip()
if probe!='0':raise RuntimeError('Partial or existing target: refusing an ambiguous import')
start=time.monotonic();last=start;written=0;digest=hashlib.sha256();log=state/'v2-import.log'
with log.open('wb') as errors, source.open('rb') as input:
    child=subprocess.Popen(['mysql','--defaults-extra-file='+str(config),'--binary-mode=1','--max-allowed-packet=256M'],stdin=subprocess.PIPE,stdout=errors,stderr=errors)
    try:
        while block:=input.read(1024*1024):
            child.stdin.write(block);digest.update(block);written+=len(block)
            if time.monotonic()-last>=30:
                print('V2 dump streamed: '+str(round(written/1048576))+' / '+str(round(source.stat().st_size/1048576))+' MiB',flush=True);last=time.monotonic()
        child.stdin.close()
    except BrokenPipeError:
        pass
    rc=child.wait()
if rc or written!=source.stat().st_size:raise RuntimeError('Import failed; details retained in private v2-import.log')
metadata={'database':'labdoo_e2e_v2_20261107','dump_bytes':written,'dump_sha256':digest.hexdigest(),'seconds':round(time.monotonic()-start,1)}
marker.write_text(json.dumps(metadata,indent=2));print('V2 dump import complete in '+str(metadata['seconds'])+' seconds.',flush=True)
