import http from 'node:http';
import { randomBytes, createHash } from 'node:crypto';
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const local = path.join(root, 'tests/upgrade/.local');
const state = path.join(local, 'state');
fs.mkdirSync(state, { recursive: true, mode: 0o700 });
const token = randomBytes(24).toString('hex');
const port = Number(process.env.LABDOO_TEST_LAB_PORT || 18412);
if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new Error('LABDOO_TEST_LAB_PORT must be a valid local port.');
const baseUrl = 'http://127.0.0.1:18411';
const suites = {
  all: [],
  navigation: ['upgrade-navigation.spec.ts'],
  entities: ['upgrade-entities.spec.ts', 'upgrade-workflows.spec.ts'],
  search: ['upgrade-workflows.spec.ts', 'dashboard-search.spec.ts', 'dashboards.spec.ts', 'regression.spec.ts'],
  regression: ['--grep-invert', 'upgrade:'],
};
const descriptions = {
  'auth.setup.ts': ['Test account setup', 'Signs in to the isolated Drupal roles and saves browser sessions for the test projects.'],
  'album-lifecycle.spec.ts': ['School album lifecycle', 'Creates and manages school albums, their images, and album references.'],
  'dashboard-search.spec.ts': ['Dashboard search behavior', 'Checks that dashboard tables, maps, and search filters show consistent results.'],
  'dashboards.spec.ts': ['Dashboard filters and sorting', 'Covers dashboard filters, sort order, pagination, and result states.'],
  'dootrip-assigned-counter.spec.ts': ['Dootrip assignment counts', 'Verifies assigned dootrip counters and their updates across related views.'],
  'education-quotes.spec.ts': ['Education quotes', 'Checks translated quotes, fallback behavior, rotation, and accessibility across languages.'],
  'forms-and-permissions.spec.ts': ['Forms and permissions', 'Verifies administrator forms and the permissions available to wiki writers.'],
  'gallery-lightbox.spec.ts': ['Gallery lightbox', 'Checks gallery browsing and image viewing when JavaScript is unavailable.'],
  'labels-and-bulk.spec.ts': ['Labels and bulk operations', 'Covers label printing and bulk dootronic actions.'],
  'regression.spec.ts': ['Core regression checks', 'Runs the established regression scenarios against the isolated application.'],
  'revision-access.spec.ts': ['Revision access control', 'Checks revision visibility for anonymous, basic, and administrator roles.'],
  'theme-analytics.spec.ts': ['Analytics consent behavior', 'Verifies analytics loading and event behavior across consent changes.'],
  'theme-palette.spec.ts': ['Theme palette settings', 'Checks palette creation, selection, permissions, and responsive rendering.'],
  'theme-performance.spec.ts': ['Theme performance behavior', 'Checks responsive layout, deferred map loading, and other performance-sensitive interactions.'],
  'theme-preview.spec.ts': ['Theme preview and boundaries', 'Verifies theme previews and the public, administration, and print boundaries.'],
  'upgrade-entities.spec.ts': ['Entity lifecycle coverage', 'Creates, reads, edits, and deletes content bundles and supporting Drupal entities.'],
  'upgrade-navigation.spec.ts': ['Menu tree navigation', 'Visits discovered internal menu destinations across the active menu tree.'],
  'upgrade-workflows.spec.ts': ['Search and user workflows', 'Covers search, contact forms, comments, team collaboration, and account pages.'],
  'visual-and-navigation.spec.ts': ['Visual and navigation checks', 'Checks key page layouts, navigation links, and administrator views and forms.'],
};

const toolInfo = {
  k6: {
    name: 'k6 load test',
    description: 'Runs the existing 13-minute load profile against five Labdoo routes on the isolated local clone.',
    tests: ['Ramp from 20 to 100 virtual users, then ramp down.', 'Thresholds: p95 response time below 2 seconds; failed requests below 2%.'],
    executable: 'k6',
  },
  lighthouse: {
    name: 'Lighthouse audits',
    description: 'Audits the standard Labdoo routes on mobile and desktop using fresh browser profiles.',
    tests: ['Performance, accessibility, best practices, and SEO.', 'Four routes × two profiles; reports include JSON and HTML.'],
    executable: 'lighthouse',
  },
};

let running = null;
function read(file, fallback) {
  try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch { return fallback; }
}
function executableExists(name) {
  const directories = (process.env.PATH || '').split(path.delimiter);
  return directories.some(directory => {
    try { fs.accessSync(path.join(directory, name), fs.constants.X_OK); return true; } catch { return false; }
  });
}
function history() {
  const dir = path.join(state, 'runs');
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir).filter(name => /^[\w-]+$/.test(name)).map(id => {
    const runDir = path.join(dir, id);
    const result = read(path.join(runDir, 'results.json'), null);
    const meta = read(path.join(runDir, 'run.json'), {});
    const k6 = read(path.join(runDir, 'k6-summary.json'), null);
    const lighthouse = read(path.join(runDir, 'lighthouse', 'summary.json'), null);
    const progress = read(path.join(runDir, 'progress.json'), null)
      || (lighthouse ? { completed: lighthouse.length, total: 8, finished: Boolean(meta.ended) } : null);
    return {
      id,
      ...meta,
      progress,
      stats: result?.stats || null,
      hasReport: fs.existsSync(path.join(runDir, 'report', 'index.html')),
      hasK6Summary: Boolean(k6),
      hasLighthouseSummary: Boolean(lighthouse),
    };
  }).sort((left, right) => Date.parse(right.started || 0) - Date.parse(left.started || 0));
}
function collectSpecs(suite, output = []) {
  output.push(...(suite.specs || []));
  for (const child of suite.suites || []) collectSpecs(child, output);
  return output;
}
function discoverPlaywright() {
  try {
    const raw = execFileSync(process.execPath, [
      path.join(root, 'tests/e2e/node_modules/@playwright/test/cli.js'),
      'test', '-c', 'upgrade.config.ts', '--list', '--reporter=json',
    ], {
      cwd: path.join(root, 'tests/e2e'),
      env: { ...process.env, E2E_STATE_DIR: state },
      maxBuffer: 16 * 1024 * 1024,
    });
    const discovered = JSON.parse(raw.toString());
    const files = (discovered.suites || []).map(suite => {
      const file = suite.file || suite.title;
      const [name, description] = descriptions[file] || [
        file.replace(/(?:\.spec)?\.ts$/, '').replaceAll('-', ' ').replace(/\b\w/g, letter => letter.toUpperCase()),
        'Playwright browser tests for this Labdoo feature.',
      ];
      const specs = collectSpecs(suite);
      return {
        name,
        description,
        count: specs.reduce((sum, spec) => sum + (spec.tests || []).length, 0),
        cases: [...new Set(specs.map(spec => spec.title))],
      };
    });
    return { files, error: null };
  } catch {
    return { files: [], error: 'Playwright discovery failed. Install its dependencies with make e2e-deps.' };
  }
}
function catalog() {
  const tools = Object.fromEntries(Object.entries(toolInfo).map(([id, info]) => [id, {
    ...info,
    available: executableExists(info.executable),
  }]));
  return { playwright: discoverPlaywright(), tools };
}
function version() {
  const source = fs.readFileSync(path.join(root, 'web/core/lib/Drupal.php'), 'utf8');
  return source.match(/const VERSION = '([^']+)'/)?.[1] || 'unknown';
}
function fingerprint() {
  const hash = createHash('sha256');
  const walk = directory => {
    for (const name of fs.readdirSync(directory).sort()) {
      if (name === 'node_modules') continue;
      const file = path.join(directory, name);
      if (fs.statSync(file).isDirectory()) walk(file);
      else if (/\.(ts|php|yml|json)$/.test(file)) hash.update(fs.readFileSync(file));
    }
  };
  walk(path.join(root, 'tests/e2e'));
  hash.update(fs.readFileSync(path.join(state, 'inventory.json')));
  return hash.digest('hex');
}
function contentType(file) {
  return {
    '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css',
    '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml',
    '.zip': 'application/zip', '.webm': 'video/webm',
  }[path.extname(file)] || 'application/octet-stream';
}

const server = http.createServer(async (req, res) => {
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; frame-ancestors 'none'");
  if (req.headers.host !== `127.0.0.1:${port}`) { res.writeHead(403); return res.end(); }
  const url = new URL(req.url, `http://127.0.0.1:${port}`);
  const json = (value, status = 200) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(value));
  };

  if (req.method === 'GET' && url.pathname === '/') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.end(fs.readFileSync(path.join(root, 'tests/upgrade/dashboard.html'), 'utf8').replace('__TOKEN__', token));
  }
  if (req.method === 'GET' && url.pathname === '/api/status') {
    const active = running ? {
      ...running,
      progress: read(path.join(state, 'runs', running.id, 'progress.json'), null)
        || (running.tool === 'lighthouse'
          ? { completed: (read(path.join(state, 'runs', running.id, 'lighthouse', 'summary.json'), []) || []).length, total: 8 }
          : null),
    } : null;
    return json({ running: active, history: history(), inventory: read(path.join(state, 'inventory.json'), null) });
  }
  if (req.method === 'GET' && url.pathname === '/api/catalog') return json(catalog());
  if (req.method === 'GET' && url.pathname === '/api/log') {
    const id = url.searchParams.get('id');
    if (!/^[\w-]+$/.test(id || '')) return json({ error: 'Invalid id' }, 400);
    res.setHeader('Content-Type', 'text/plain; charset=utf-8');
    const file = path.join(state, 'runs', id, 'console.log');
    return res.end(fs.existsSync(file) ? fs.readFileSync(file).subarray(-120000) : '');
  }
  if (req.method === 'GET' && url.pathname.startsWith('/reports/')) {
    const parts = decodeURIComponent(url.pathname.slice('/reports/'.length)).split('/');
    if (!/^[\w-]+$/.test(parts[0]) || parts.some(part => part === '..' || part.startsWith('.'))) { res.writeHead(403); return res.end(); }
    const directory = path.resolve(state, 'runs', parts[0], 'report');
    const file = path.resolve(directory, ...parts.slice(1));
    if (!file.startsWith(directory + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404); return res.end(); }
    res.setHeader('Content-Type', contentType(file));
    return fs.createReadStream(file).pipe(res);
  }
  if (req.method === 'GET' && url.pathname.startsWith('/artifacts/')) {
    const parts = decodeURIComponent(url.pathname.slice('/artifacts/'.length)).split('/');
    if (!/^[\w-]+$/.test(parts[0]) || parts.length < 2 || parts.some(part => part === '..' || part.startsWith('.'))) { res.writeHead(403); return res.end(); }
    const directory = path.resolve(state, 'runs', parts[0]);
    const file = path.resolve(directory, ...parts.slice(1));
    if (!file.startsWith(directory + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404); return res.end(); }
    res.setHeader('Content-Type', contentType(file));
    return fs.createReadStream(file).pipe(res);
  }
  if (req.method === 'POST' && url.pathname === '/api/run') {
    if (req.headers.origin !== `http://127.0.0.1:${port}` || req.headers['x-labdoo-token'] !== token) return json({ error: 'Invalid origin or token' }, 403);
    if (running) return json({ error: 'Another test run is already active.' }, 409);
    let body = '';
    for await (const chunk of req) { body += chunk; if (body.length > 1024) return json({ error: 'Request too large' }, 413); }
    let input;
    try { input = JSON.parse(body); } catch { return json({ error: 'Invalid JSON' }, 400); }
    const tool = input.tool;
    if (!['playwright', 'k6', 'lighthouse'].includes(tool)) return json({ error: 'Choose a supported test tool.' }, 400);
    if (tool === 'playwright' && !Object.hasOwn(suites, input.suite)) return json({ error: 'Choose a supported Playwright suite.' }, 400);
    if (!fs.existsSync(path.join(state, 'connection.json')) || !fs.existsSync(path.join(state, 'inventory.json'))) {
      return json({ error: 'Prepare the isolated test lab first.' }, 412);
    }
    if (tool !== 'playwright' && !executableExists(toolInfo[tool].executable)) return json({ error: `${toolInfo[tool].executable} is not installed or available on PATH.` }, 412);
    let site;
    try {
      site = await fetch(baseUrl, { signal: AbortSignal.timeout(5000) });
      if (!site.ok || site.headers.get('x-labdoo-e2e') !== 'integration') throw new Error();
    } catch {
      return json({ error: `Start the isolated Drupal server at ${baseUrl} first.` }, 412);
    }

    const id = `${tool}-${Date.now()}`;
    const dir = path.join(state, 'runs', id);
    fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
    const meta = {
      tool,
      toolName: tool === 'playwright' ? 'Playwright E2E' : toolInfo[tool].name,
      suite: tool === 'playwright' ? input.suite : null,
      core: version(),
      started: new Date().toISOString(),
      commit: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim(),
    };
    if (tool === 'playwright') meta.fingerprint = fingerprint();
    fs.writeFileSync(path.join(dir, 'run.json'), JSON.stringify(meta, null, 2));
    if (tool === 'playwright') {
      fs.writeFileSync(path.join(dir, 'progress.json'), JSON.stringify({ tool, completed: 0, total: 0, passed: 0, failed: 0, skipped: 0, other: 0, finished: false }), { mode: 0o600 });
    }
    const output = fs.openSync(path.join(dir, 'console.log'), 'w');
    let command;
    let args;
    let cwd = root;
    let env = { ...process.env };
    if (tool === 'playwright') {
      command = process.execPath;
      args = [path.join(root, 'tests/e2e/node_modules/@playwright/test/cli.js'), 'test', '-c', 'upgrade.config.ts', ...suites[input.suite]];
      cwd = path.join(root, 'tests/e2e');
      env = { ...env, E2E_RUN_ID: id, E2E_STATE_DIR: state, E2E_LOCAL_PORT: '18411' };
    } else if (tool === 'k6') {
      command = 'k6';
      args = ['run', '--summary-export', path.join(dir, 'k6-summary.json'), '-e', `BASE_URL=${baseUrl}`, path.join(root, 'tests/performance/labdoo-load.js')];
    } else {
      command = 'python3';
      args = [path.join(root, 'tests/performance/lighthouse.py'), '--base-url', baseUrl, '--output', path.join(dir, 'lighthouse'), '--lighthouse', 'lighthouse'];
    }
    const child = spawn(command, args, { cwd, env, stdio: ['ignore', output, output] });
    fs.closeSync(output);
    running = { id, pid: child.pid, ...meta };
    const finish = (code, error) => {
      fs.writeFileSync(path.join(dir, 'run.json'), JSON.stringify({ ...meta, ended: new Date().toISOString(), exitCode: code, error }, null, 2));
      running = null;
    };
    child.once('error', error => finish(null, error.message));
    child.once('exit', code => finish(code));
    return json({ id }, 202);
  }
  res.writeHead(404);
  res.end();
});

server.listen(port, '127.0.0.1', () => console.log(`Labdoo Test Lab · http://127.0.0.1:${port}`));
process.on('SIGTERM', () => { if (running) process.kill(running.pid, 'SIGTERM'); server.close(); });
