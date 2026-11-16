"""Run missing D10 updates through the real intermediate Honeypot release."""
import hashlib, io, json, shutil, subprocess, sys, urllib.request, zipfile
from pathlib import Path
from lab import ROOT, LAB, drush
if "const VERSION = '10." not in (ROOT/'web/core/lib/Drupal.php').read_text(): raise RuntimeError('Run the intermediate release on Drupal 10 before the major upgrade')
# The guard must succeed before touching dependencies or databases.
drush('php:script', str(ROOT/'tests/e2e/support/assert-isolated.php'))
archive=urllib.request.urlopen('https://ftp.drupal.org/files/projects/honeypot-2.1.4.zip',timeout=60).read()
if hashlib.sha256(archive).hexdigest()!='6c9c8a216beb5f5c7751cd8fcc91b93e02cfbde182cf2ea92d42d072d735af75': raise RuntimeError('Unexpected intermediate release checksum')
module=ROOT/'web/modules/contrib/honeypot'
backup=LAB/'honeypot-current'
if backup.exists(): raise RuntimeError('An intermediate update is already in progress')
module.rename(backup)
try:
    module.mkdir()
    for name in (z:=zipfile.ZipFile(io.BytesIO(archive))).namelist():
        parts=Path(name).parts
        if not parts or parts[0]!='honeypot' or '..' in parts: raise RuntimeError('Unsafe archive path')
        target=module.joinpath(*parts[1:])
        if name.endswith('/'): target.mkdir(parents=True,exist_ok=True)
        else:
            target.parent.mkdir(parents=True,exist_ok=True); target.write_bytes(z.read(name))
    drush('updatedb','-y')
finally:
    shutil.rmtree(module)
    backup.rename(module)
drush('cr')
drush('updatedb','-y')
