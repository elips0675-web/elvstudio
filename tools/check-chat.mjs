import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');

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
const result = spawnSync(php, ['tools/chat-check.php'], { cwd: ROOT, stdio: 'inherit' });

if (result.status !== 0) {
  console.error('chat-check failed');
  process.exit(1);
}

console.log('chat-check ok');
