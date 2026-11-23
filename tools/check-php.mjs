import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const SCRIPTS = [
  'tools/chat-check.php',
  'tools/seo-check.php',
  'tools/booking-check.php',
  'tools/presets-check.php',
  'tools/download-check.php',
];

function commandWorks(command, args) {
  const result = spawnSync(command, args, { stdio: 'ignore' });

  return result.status === 0;
}

function resolvePhp() {
  if (process.env.PHP_BIN) {
    return process.env.PHP_BIN;
  }

  if (commandWorks('php', ['-v'])) {
    return 'php';
  }

  if (process.platform === 'win32') {
    const laragon = 'C:\\laragon\\bin\\php';

    if (fs.existsSync(laragon)) {
      const versions = fs.readdirSync(laragon)
        .map((name) => path.join(laragon, name, 'php.exe'))
        .filter((file) => fs.existsSync(file))
        .sort()
        .reverse();

      if (versions.length) {
        return versions[0];
      }
    }
  }

  throw new Error('PHP not found. Set PHP_BIN to the php executable.');
}

const php = resolvePhp();
const failed = [];

for (const script of SCRIPTS) {
  console.log('\n== ' + script + ' ==');

  const result = spawnSync(php, [script], { cwd: ROOT, stdio: 'inherit' });

  if (result.status !== 0) {
    failed.push(script);
  }
}

if (failed.length) {
  console.error('\nfailed: ' + failed.join(', '));
  process.exit(1);
}

console.log('\nphp checks passed');
