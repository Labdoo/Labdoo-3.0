"""Compare a private source manifest with an isolated restored filesystem."""
import hashlib
from pathlib import Path, PurePosixPath
import stat


def compare_manifest(manifest, restored_root, readable_as_web_user=None):
    root = Path(restored_root).resolve(strict=True)
    if root == Path('/'):
        raise ValueError('The live root is not a recovery target')
    paths = []
    for record in manifest['files']:
        source = PurePosixPath(record['path'])
        if not source.is_absolute() or '..' in source.parts:
            raise ValueError('Unsafe manifest path')
        if not (str(source).startswith('/mnt/data/') or str(source).startswith('/etc/')):
            raise ValueError('Unexpected recovery scope')
        relative = Path(*source.parts[1:])
        target = root / relative
        if target.is_symlink() or not target.resolve().is_relative_to(root):
            raise ValueError('A manifest entry escapes the restored filesystem')
        paths.append((record, target))
    results = []
    for record, target in paths:
        differences = []
        if not target.is_file():
            differences.append('missing')
        else:
            info = target.stat()
            actual = {'size': info.st_size, 'uid': info.st_uid, 'gid': info.st_gid,
                      'mode': stat.S_IMODE(info.st_mode),
                      'sha256': hashlib.sha256(target.read_bytes()).hexdigest()}
            differences.extend(key for key in actual if actual[key] != record[key])
            if readable_as_web_user is not None:
                if readable_as_web_user(record['path']) != record['www_data_readable']:
                    differences.append('www_data_readable')
        results.append({'path': record['path'], 'differences': differences})
    return {'checked': len(results), 'passed': sum(not r['differences'] for r in results),
            'web_readability_checked': readable_as_web_user is not None, 'files': results}
