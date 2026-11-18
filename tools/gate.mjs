import { spawnSync } from 'node:child_process';

const scripts = [
  'check-urls.mjs',
  'check-seo.mjs',
  'check-headers.mjs',
  'check-budget.mjs',
  'check-orphans.mjs',
  'check-package.mjs',
  'check-brand.mjs',
  'check-plans.mjs',
  'check-console.mjs',
];

const failed = [];

for (const script of scripts) {
  console.log('\n== ' + script + ' ==');
  const result = spawnSync(process.execPath, ['tools/' + script], { stdio: 'inherit' });

  if (result.status !== 0) {
    failed.push(script);
  }
}

if (failed.length) {
  console.error('\nfailed: ' + failed.join(', '));
  process.exit(1);
}

console.log('\nall gates passed');
