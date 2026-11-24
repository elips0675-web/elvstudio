import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const SCRIPTS = [
  'tools/chat-check.php',
  'tools/search-check.php',
  'tools/seo-check.php',
  'tools/admin-check.php',
  'tools/booking-check.php',
  'tools/presets-check.php',
  'tools/download-check.php',
  'tools/account-check.php',
  'tools/tickets-check.php',
  'tools/community-check.php',
];

const PHP_PROBE = 'echo (int) (extension_loaded("zip") && extension_loaded("mbstring") && extension_loaded("pdo_mysql"));';

/**
 * Проверки запускают October и собирают ZIP, поэтому PHP без zip, mbstring
 * или pdo_mysql не годится: gate должен падать на коде, а не на выборе
 * бинарника. Laragon держит несколько версий — берём старшую подходящую.
 */
function phpSupports(requested) {
  const probe = spawnSync(requested, ['-r', PHP_PROBE], { encoding: 'utf8', stdio: 'pipe' });

  return probe.status === 0 && String(probe.stdout).trim() === '1';
}

function resolvePhp() {
  if (process.env.PHP_BIN) {
    return process.env.PHP_BIN;
  }

  const candidates = ['php'];
  const laragon = 'C:\\laragon\\bin\\php';

  if (process.platform === 'win32' && fs.existsSync(laragon)) {
    fs.readdirSync(laragon)
      .map((name) => path.join(laragon, name, 'php.exe'))
      .filter((file) => fs.existsSync(file))
      .sort()
      .reverse()
      .forEach((file) => candidates.push(file));
  }

  return candidates.find(phpSupports) || '';
}

const php = resolvePhp();

if (!php) {
  console.error('PHP с расширениями zip, mbstring и pdo_mysql не найден. Укажите PHP_BIN.');
  process.exit(1);
}

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
