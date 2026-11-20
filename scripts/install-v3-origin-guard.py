#!/usr/bin/env python3
"""Install the v3 Apache origin guard; this script never changes network or SSH."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import uuid

VHOSTS = ['v3.labdoo.org.conf', 'v3.labdoo.org-le-ssl.conf', '000-default.conf']
AVAILABLE = Path('/etc/apache2/sites-available')
GUARD = Path('/etc/apache2/labdoo-private/v3-origin-guard.conf')
BACKUPS = Path('/root/labdoo-origin-guard-backups')
INCLUDE = 'Include ' + str(GUARD)
TEMPLATE = Path(__file__).resolve().parent / 'templates/v3-origin-guard.conf.template'


def digest(value):
    return hashlib.sha256(value).hexdigest()


def render(config, template, expected):
    """Reuse only a regex-safe token matching the reviewed CloudFront digest."""
    candidates = set()
    for line in config.splitlines():
        if line.strip().startswith('RewriteCond') and 'X-Origin-Verify' in line:
            parts = shlex.split(line)
            if len(parts) < 3 or parts[1] != '%{HTTP:X-Origin-Verify}':
                continue
            token = parts[2].removeprefix('!').removeprefix('^').removesuffix('$')
            if re.fullmatch('[A-Za-z0-9_-]{16,}', token) and digest(token.encode()) == expected:
                candidates.add(token)
    if len(candidates) != 1 or template.count('__LABDOO_ORIGIN_TOKEN__') != 1:
        raise ValueError('Expected existing origin token or template was not verified')
    return template.replace('__LABDOO_ORIGIN_TOKEN__', candidates.pop()).encode()


def add_include(config):
    if INCLUDE in config or config.count('<VirtualHost ') != 1:
        raise ValueError('Unexpected or already configured VirtualHost')
    lines = config.splitlines(keepends=True)
    for index, line in enumerate(lines):
        if line.strip().startswith('<VirtualHost '):
            lines.insert(index + 1, '    ' + INCLUDE + '\n')
            return ''.join(lines).encode()
    raise ValueError('Missing VirtualHost')


def replace(path, value, mode, uid=0, gid=0):
    temporary = path.with_name(path.name + '.origin-guard-' + uuid.uuid4().hex)
    try:
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
        with os.fdopen(fd, 'wb') as output:
            output.write(value)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temporary, mode)
        os.chown(temporary, uid, gid)
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def apache_check():
    result = subprocess.run(['apache2ctl', 'configtest'], capture_output=True)
    if result.returncode:
        # Never print configuration diagnostics which might contain the token.
        raise RuntimeError('Apache configtest failed; private configuration restored')


def restore(backup):
    if backup.parent != BACKUPS or not backup.is_dir():
        raise ValueError('Unexpected private rollback location')
    manifest = json.loads((backup / 'manifest.json').read_text())
    if set(manifest['files']) != set(VHOSTS):
        raise ValueError('Unexpected rollback file set')
    if GUARD.exists() and digest(GUARD.read_bytes()) != manifest['guard_sha256']:
        raise ValueError('Guard drift prevents automatic rollback')
    for name, meta in manifest['files'].items():
        path = AVAILABLE / name
        if digest(path.read_bytes()) not in [meta['before'], meta['after']]:
            raise ValueError('VirtualHost drift prevents automatic rollback')
        if digest((backup / name).read_bytes()) != meta['before']:
            raise ValueError('Backup checksum mismatch')
    for name, meta in manifest['files'].items():
        replace(AVAILABLE / name, (backup / name).read_bytes(), meta['mode'], meta['uid'], meta['gid'])
    GUARD.unlink(missing_ok=True)
    apache_check()
    subprocess.run(['systemctl', 'reload', 'apache2'], check=True, capture_output=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--apply', action='store_true')
    action.add_argument('--rollback', type=Path)
    parser.add_argument('--expected-token-sha256')
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error('Run as root on the inspected v3 server')
    os.umask(0o077)
    if args.rollback:
        restore(args.rollback)
        print(json.dumps({'restored': True, 'ssh_changed': False}))
        return
    if not re.fullmatch('[0-9a-f]{64}', args.expected_token_sha256 or ''):
        parser.error('A reviewed origin-token SHA256 is required')
    if GUARD.exists() or not all((Path('/etc/apache2/sites-enabled') / n).resolve() == AVAILABLE / n for n in VHOSTS):
        raise ValueError('Unexpected enabled vhosts or existing guard')
    apache_check()
    before = {n: (AVAILABLE / n).read_bytes() for n in VHOSTS}
    after = {n: add_include(v.decode()) for n, v in before.items()}
    guard = render(before['v3.labdoo.org.conf'].decode(), TEMPLATE.read_text(), args.expected_token_sha256)
    backup = BACKUPS / (datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ-') + uuid.uuid4().hex[:8])
    backup.mkdir(parents=True, mode=0o700)
    manifest = {'guard_sha256': digest(guard), 'files': {}}
    for name, value in before.items():
        stat = (AVAILABLE / name).stat()
        (backup / name).write_bytes(value)
        manifest['files'][name] = {'before': digest(value), 'after': digest(after[name]),
                                  'mode': stat.st_mode & 0o777, 'uid': stat.st_uid, 'gid': stat.st_gid}
    (backup / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    GUARD.parent.mkdir(mode=0o700, exist_ok=True)
    try:
        replace(GUARD, guard, 0o600)
        for name, value in after.items():
            meta = manifest['files'][name]
            replace(AVAILABLE / name, value, meta['mode'], meta['uid'], meta['gid'])
        apache_check()
        subprocess.run(['systemctl', 'reload', 'apache2'], check=True, capture_output=True)
    except Exception:
        restore(backup)
        raise RuntimeError('Origin guard installation failed; original configuration restored') from None
    print(json.dumps({'installed': True, 'vhosts': len(VHOSTS), 'backup': str(backup),
                      'guard_mode': '0600', 'ssh_changed': False, 'secret_rotated': False}))


if __name__ == '__main__':
    main()
