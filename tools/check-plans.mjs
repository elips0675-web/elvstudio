/**
 * check-plans — тарифы витрины приходят из Settings, а не из кода.
 *
 * Запуск: node tools/check-plans.mjs [--base=http://127.0.0.1:8090]
 *
 * Данные проверяет `tools/check-plans.php` (меняет настройки только в памяти
 * процесса), разметку — этот файл: подписи и число карточек на /pricing/ и
 * /order/ должны совпадать с тем, что отдаёт Catalog. Входит в `gate.mjs`.
 */

import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const args = process.argv.slice(2);
const baseArg = args.find((arg) => arg.startsWith('--base='));
const base = (baseArg ? baseArg.slice('--base='.length) : 'http://127.0.0.1:8090').replace(/\/$/, '');

const problems = [];

const PHP_PROBE = 'echo (int) (extension_loaded("zip") && extension_loaded("mbstring"));';

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

  if (fs.existsSync(laragon)) {
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
  console.error('FAIL: PHP с расширениями zip и mbstring не найден (PHP_BIN или C:\\laragon\\bin\\php)');
  process.exit(1);
}

let report = null;

try {
  const output = execFileSync(php, ['tools/check-plans.php'], {
    cwd: root,
    encoding: 'utf8',
    maxBuffer: 4 * 1024 * 1024,
    stdio: 'pipe',
  });

  report = JSON.parse(output);
} catch (error) {
  const text = String(error.stdout || '') + String(error.stderr || '');

  try {
    report = JSON.parse(String(error.stdout || ''));
  } catch (parseError) {
    console.error('FAIL: tools/check-plans.php не вернул JSON — ' + text.trim().split('\n')[0]);
    process.exit(1);
  }
}

(report.problems || []).forEach((problem) => problems.push('данные: ' + problem));

const labels = Object.values(report.labels || {});
const plans = report.plans || [];

if (plans.length !== (report.keys || []).length) {
  problems.push('витрина: plans() отдаёт ' + plans.length + ' тарифов, а включено ' + (report.keys || []).length);
}

const themeDir = path.join(root, 'themes/elv-studio');
const themeFiles = [];

const walk = (dir) => {
  fs.readdirSync(dir, { withFileTypes: true }).forEach((entry) => {
    const full = path.join(dir, entry.name);

    if (entry.isDirectory()) {
      if (entry.name !== 'assets' && entry.name !== 'content') {
        walk(full);
      }

      return;
    }

    if (entry.name.endsWith('.htm')) {
      themeFiles.push(full);
    }
  });
};

walk(themeDir);

themeFiles.forEach((file) => {
  const source = fs.readFileSync(file, 'utf8');
  const relative = path.relative(root, file).replace(/\\/g, '/');

  labels.forEach((label) => {
    if (source.includes("'" + label + "'") || source.includes('"' + label + '"')) {
      problems.push(relative + ' — подпись тарифа «' + label + '» зашита строкой, нужен elv_plan_label/plans');
    }
  });
});

const get = async (url) => {
  try {
    const response = await fetch(base + url, { redirect: 'manual' });

    return { status: response.status, html: await response.text() };
  } catch (error) {
    problems.push(url + ' — запрос не выполнен: ' + error.message);

    return { status: 0, html: '' };
  }
};

const pricing = await get('/pricing/');
const order = await get('/order/');
const home = await get('/');
const terms = await get('/terms/');

const mainOf = (html) => (/<main[\s\S]*?<\/main>/i.exec(html) || [''])[0];

const pricingMain = mainOf(pricing.html);
const orderMain = mainOf(order.html);

for (const [url, page] of [['/pricing/', pricing], ['/order/', order], ['/', home], ['/terms/', terms]]) {
  if (page.status !== 200) {
    problems.push(url + ' — статус ' + page.status + ', ожидался 200');
    continue;
  }

  const main = mainOf(page.html);

  labels.forEach((label) => {
    if (!main.includes(label)) {
      problems.push(url + ' — нет подписи тарифа «' + label + '» (витрина и настройки разошлись)');
    }
  });
}

const cards = (pricingMain.match(/class="price-card__value"/g) || []).length;

if (cards !== plans.length) {
  problems.push('/pricing/ — карточек ' + cards + ', а тарифов в plans() ' + plans.length);
}

const picker = (orderMain.match(/name="plan"\s+value="(?:single|multi|extended)"/g) || []).length;

if (picker < plans.length) {
  problems.push('/order/ — вариантов тарифа ' + picker + ', а plans() ' + plans.length);
}

if (problems.length) {
  problems.forEach((problem) => console.error('FAIL: ' + problem));
  process.exit(1);
}

console.log('тарифы из настроек: ' + plans.length + ' (' + labels.join(' / ') + '), проверок данных ' + report.checks);
