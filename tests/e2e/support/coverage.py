"""Produce a per-card coverage report from one completed Playwright run."""
import json
import sys
from pathlib import Path
state = Path(__file__).resolve().parents[4] / 'state'
run = state / 'runs' / sys.argv[1]
report = json.loads((run / 'playwright-results.json').read_text())
cards = json.loads((state / 'cards.json').read_text())
by_card = {card['id']: [] for card in cards}
def visit(suite):
    for spec in suite.get('specs', []):
        for test in spec.get('tests', []):
            for annotation in test.get('annotations', []):
                if annotation['type'] == 'todoist' and annotation['description'] in by_card:
                    by_card[annotation['description']].append({'project': test['projectName'], 'test': spec['title'], 'status': test['status']})
    for child in suite.get('suites', []): visit(child)
visit(report)
rows = []
for card in cards:
    tests = by_card[card['id']]
    status = 'passed' if tests and all(t['status'] == 'expected' for t in tests) else 'failed' if tests else 'uncovered'
    rows.append({'id': card['id'], 'title': card['title'], 'branch': card['branch'], 'status': status, 'tests': tests})
(run / 'card-coverage.json').write_text(json.dumps(rows, indent=2, ensure_ascii=False))
md = ['# Labdoo: pruebas E2E', '', f"Resultado: {report['stats']}", '', '| Tarjeta | Rama | Resultado | Pruebas |', '|---|---|---|---|']
for row in rows: md.append(f"| {row['title']} | {row['branch']} | {row['status']} | {len(row['tests'])} |")
(run / 'card-coverage.md').write_text('\n'.join(md) + '\n')
counts = {status: sum(row['status'] == status for row in rows) for status in ['passed', 'failed', 'uncovered']}
print(json.dumps({'stats': report['stats'], 'cards': counts}, ensure_ascii=False))
raise SystemExit(0 if counts['passed'] == len(cards) and not report['stats']['unexpected'] and not report['stats']['skipped'] else 1)
