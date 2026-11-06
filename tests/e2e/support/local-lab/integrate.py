import json
import subprocess
from pathlib import Path
import yaml

LAB = next(parent for parent in Path(__file__).resolve().parents if parent.name == '.e2e-lab')
ROOT = LAB / 'integration'
MISSING = object()

def git(*args, check=True):
    return subprocess.run(['git', *args], cwd=ROOT, capture_output=True, text=True, check=check)

def combine(base, ours, theirs, path=()):
    if ours == theirs: return ours
    if ours == base: return theirs
    if theirs == base: return ours
    if ours is MISSING or theirs is MISSING:
        if path == ('third_party_settings',) or path in [('content', 'field_edoovillage_photo_album'), ('content', 'field_edoo_additional_notif_em')]:
            return MISSING
        raise RuntimeError('Deletion/modification conflict: ' + '.'.join(path))
    if isinstance(ours, dict) and isinstance(theirs, dict) and (isinstance(base, dict) or base is MISSING):
        if base is MISSING: base = {}
        result = {}
        for key in dict.fromkeys([*base, *ours, *theirs]):
            value = combine(base.get(key, MISSING), ours.get(key, MISSING), theirs.get(key, MISSING), path + (key,))
            if value is not MISSING: result[key] = value
        return result
    if all(isinstance(v, list) for v in [base, ours, theirs]):
        result = [v for v in base if v in ours and v in theirs]
        for v in [*ours, *theirs]:
            if v not in base and v not in result: result.append(v)
        return result
    if path[-1:] == ('pages',) and all(isinstance(v, str) for v in [ours, theirs]):
        return '\n'.join(dict.fromkeys(ours.splitlines() + theirs.splitlines()))
    raise RuntimeError('Conflicting values: ' + '.'.join(path))

def resolve():
    paths = git('diff', '--name-only', '--diff-filter=U').stdout.splitlines()
    css = []
    for name in paths:
        if name.endswith('/css/main.css'):
            css.append(name)
            continue
        base, ours, theirs = [git('show', f':{stage}:{name}').stdout for stage in [1, 2, 3]]
        if name.endswith(('.sass', '.module')):
            if not ours.startswith(base) or not theirs.startswith(base):
                raise RuntimeError('Non-append source conflict: ' + name)
            addition = theirs[len(base):]
            result = ours if addition in ours else ours + addition
        elif name.endswith('.yml'):
            result = yaml.safe_dump(combine(*[yaml.safe_load(v) for v in [base, ours, theirs]]), sort_keys=False, allow_unicode=True)
        else:
            raise RuntimeError('Manual resolution required: ' + name)
        (ROOT / name).write_text(result)
        git('add', name)
    for name in css:
        theme = ROOT / 'web/themes/custom/labdoo'
        subprocess.run(['node', '/var/www/html/labdoo3/web/themes/custom/labdoo/node_modules/sass/sass.js', '--style=compressed', '--no-source-map', str(theme / 'sass/main.sass'), str(ROOT / name)], check=True, capture_output=True)
        git('add', name)
    git('commit', '--no-edit')
    with (LAB / 'state/integration.jsonl').open('a') as output:
        output.write(json.dumps({'resolved': paths}) + '\n')
    print('Resolved integration overlaps: ' + ', '.join(paths), flush=True)

branches = [t['branch'] for t in json.loads((LAB / 'state/cards.json').read_text())]
if git('diff', '--name-only', '--diff-filter=U').stdout.strip(): resolve()
for branch in branches:
    if git('merge-base', '--is-ancestor', branch, 'HEAD', check=False).returncode == 0: continue
    result = git('merge', '--no-ff', '--no-edit', branch, check=False)
    if result.returncode:
        if git('diff', '--name-only', '--diff-filter=U').stdout.strip(): resolve()
        else: raise RuntimeError(result.stdout + result.stderr)
    print('Integrated ' + branch, flush=True)
