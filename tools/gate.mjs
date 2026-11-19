import { spawnSync } from 'node:child_process';

const gates = [
  { script: 'check-urls.mjs' },
  { script: 'check-blog.mjs' },
  { script: 'check-seo.mjs' },
  { script: 'check-headers.mjs' },
  { script: 'check-budget.mjs' },
  { script: 'check-orphans.mjs' },
  { script: 'check-package.mjs' },
  { script: 'check-brand.mjs' },
  { script: 'check-plans.mjs' },
  { script: 'check-console.mjs' },
  { script: 'check-perf.mjs', args: ['--ttfb-only'] },
];

const failed = [];

for (const gate of gates) {
  const args = gate.args || [];

  console.log('\n== ' + gate.script + (args.length ? ' ' + args.join(' ') : '') + ' ==');

  const result = spawnSync(process.execPath, ['tools/' + gate.script, ...args], { stdio: 'inherit' });

  if (result.status !== 0) {
    failed.push(gate.script);
  }
}

if (failed.length) {
  console.error('\nfailed: ' + failed.join(', '));
  process.exit(1);
}

console.log('\nall gates passed');
