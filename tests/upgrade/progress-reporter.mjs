import fs from 'node:fs';
import path from 'node:path';

export default class LabdooProgressReporter {
  constructor() {
    this.completed = 0;
    this.counts = { passed: 0, failed: 0, skipped: 0, other: 0 };
    const state = process.env.E2E_STATE_DIR;
    const run = process.env.E2E_RUN_ID;
    this.file = state && run && /^[\w-]+$/.test(run) ? path.join(state, 'runs', run, 'progress.json') : null;
  }

  write(finished = false) {
    if (!this.file) return;
    const data = JSON.stringify({
      tool: 'playwright',
      completed: this.completed,
      total: this.total || 0,
      ...this.counts,
      finished,
      updated: new Date().toISOString(),
    });
    const temporary = `${this.file}.tmp`;
    fs.writeFileSync(temporary, data, { mode: 0o600 });
    fs.renameSync(temporary, this.file);
  }

  onBegin(config, suite) {
    this.total = suite.allTests().length;
    this.write();
  }

  onTestEnd(test, result) {
    this.completed += 1;
    if (result.status === 'passed') this.counts.passed += 1;
    else if (result.status === 'failed' || result.status === 'timedOut') this.counts.failed += 1;
    else if (result.status === 'skipped') this.counts.skipped += 1;
    else this.counts.other += 1;
    this.write();
  }

  onEnd() {
    this.write(true);
  }
}
