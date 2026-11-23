"""Publish immutable copies of public code assets, retaining previous hashes."""
import hashlib
import json
from pathlib import Path
import re

THEME = Path(__file__).resolve().parent.parent
WEB = THEME.parents[2]
OUTPUT = THEME / 'static'
ALLOWED = {'.js', '.css', '.png', '.webp', '.svg', '.woff2'}


def publish(sources, web=WEB, output=OUTPUT, bundles=()):
    manifest = {}
    visiting = set()

    # Preserve vendor-relative imports, marker images and retina companions.
    for directory in sorted(bundles):
        files = sorted(p for p in directory.rglob('*') if p.is_file()
                       and (p.suffix in ALLOWED or 'LICENSE' in p.name.upper() or 'LICENCE' in p.name.upper()))
        fingerprint = hashlib.sha256()
        for source in files:
            source.resolve().relative_to(web.resolve())
            fingerprint.update(source.relative_to(directory).as_posix().encode() + b'\0')
            fingerprint.update(hashlib.sha256(source.read_bytes()).digest())
        digest = fingerprint.hexdigest()
        for source in files:
            destination = output / 'vendor' / digest / source.relative_to(directory)
            destination.parent.mkdir(parents=True, exist_ok=True)
            data = source.read_bytes()
            if destination.exists():
                if destination.read_bytes() != data:
                    raise ValueError('An immutable bundle cannot be replaced')
            else:
                destination.write_bytes(data)
            manifest[source.relative_to(web).as_posix()] = destination.relative_to(web).as_posix()

    def copy(source):
        source = source.resolve()
        key = source.relative_to(web.resolve()).as_posix()
        if key in manifest:
            return manifest[key]
        if source.suffix not in ALLOWED or source in visiting:
            raise ValueError('Unsupported or cyclic static dependency: ' + key)
        visiting.add(source)
        data = source.read_bytes()
        if source.suffix == '.css':
            def replace(match):
                url = match.group(1).strip(' \t\r\n\"\'')
                if url.startswith(('data:', '/', '#')) or '://' in url:
                    return match.group(0)
                dependency = (source.parent / url.split('?', 1)[0]).resolve()
                target = copy(dependency)
                return 'url(../' + '/'.join(target.split('/')[-2:]) + ')'
            data = re.sub(r'url\(([^)]+)\)', replace, data.decode()).encode()
        digest = hashlib.sha256(data).hexdigest()
        destination = output / digest / source.name
        destination.parent.mkdir(parents=True, exist_ok=True)
        if destination.exists():
            if destination.read_bytes() != data:
                raise ValueError('An immutable asset cannot be replaced')
        else:
            destination.write_bytes(data)
        manifest[key] = destination.relative_to(web).as_posix()
        visiting.remove(source)
        return manifest[key]

    for source in sorted(sources):
        copy(source)
    output.mkdir(parents=True, exist_ok=True)
    temporary = output / 'manifest.json.tmp'
    temporary.write_text(json.dumps(dict(sorted(manifest.items())), indent=2) + '\n')
    temporary.replace(output / 'manifest.json')
    return manifest


if __name__ == '__main__':
    sources = [p for directory in [THEME / 'images', THEME / 'js', THEME / 'css']
               for p in directory.rglob('*') if p.is_file() and p.suffix in ALLOWED]
    sources += [WEB / 'themes/custom/labdoo/js/global-search.js',
                WEB / 'themes/custom/labdoo/js/labdoo-map.js',
                WEB / 'modules/contrib/charts/modules/charts_chartjs/js/charts_chartjs.js']
    print('Published', len(publish(sources, bundles=[p for p in (THEME / 'vendor').iterdir() if p.is_dir()])), 'versioned public assets')
