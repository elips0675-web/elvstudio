/**
 * check-perf — время ответа сервера и Lighthouse «все метрики 100».
 *
 * Запуск: node tools/check-perf.mjs [url] [--runs=3] [--ttfb-only]
 *
 * Сначала — детерминированная часть: 15 запросов подряд, p90 времени ответа
 * не выше 300 мс. Это проверка того, что стенд стартует с OPcache: без него
 * p90 выходит 900+ мс (PHP компилирует фреймворк на каждом запросе), и
 * Lighthouse даёт 99 даже при TBT 0. Затем — Lighthouse (мобильный профиль,
 * как в PageSpeed): медиана Performance 100, худший прогон не ниже 95. Нужен
 * Chrome и сеть для `npx lighthouse` (в CI не заведён, как `check:console`).
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const args = process.argv.slice(2);
const runsArg = args.find((arg) => arg.startsWith('--runs='));
const runs = Math.max(1, Number(runsArg ? runsArg.split('=')[1] : 3));
const ttfbOnly = args.includes('--ttfb-only');
const url = (args.find((arg) => !arg.startsWith('--')) || 'http://127.0.0.1:8090/blog/').replace(/\/$/, '/');

const npx = process.platform === 'win32'
  ? path.join(path.dirname(process.execPath), 'node_modules', 'npm', 'bin', 'npx-cli.js')
  : 'npx';

const fetchText = async (target) => {
  const response = await fetch(target);

  return response.text();
};

try {
  await fetchText(url);
} catch {
  console.error('FAIL: ' + url + ' не отвечает — поднимите стенд');
  process.exit(1);
}

const probe = async (count) => {
  const timings = [];

  for (let index = 0; index < count; index += 1) {
    const started = process.hrtime.bigint();

    await fetchText(url);

    timings.push(Number(process.hrtime.bigint() - started) / 1e6);
  }

  return timings.sort((left, right) => left - right);
};

await probe(3);

const ttfb = await probe(15);
const ttfbMedian = Math.round(ttfb[Math.floor(ttfb.length / 2)]);
const ttfbP90 = Math.round(ttfb[Math.floor(ttfb.length * 0.9)]);

console.log('ответ сервера: медиана ' + ttfbMedian + ' мс, p90 ' + ttfbP90 + ' мс (15 запросов)');

if (ttfbP90 > 300) {
  console.error('FAIL: p90 ' + ttfbP90 + ' мс — стенд отвечает как без OPcache.'
    + ' Проверьте, что сервер поднят строкой из `запуск.bat` (флаги zend_extension/opcache).');
  process.exit(1);
}

if (ttfbOnly) {
  console.log('OK: сервер отвечает быстро (OPcache на месте)');
  process.exit(0);
}

const reports = [];

for (let index = 1; index <= runs; index += 1) {
  const file = path.join(os.tmpdir(), 'elv-perf-' + process.pid + '-' + index + '.json');

  execFileSync(process.execPath, [
    npx, '--yes', 'lighthouse@12.8.2', url,
    '--output=json', '--output-path=' + file,
    '--chrome-flags=--headless=new --no-sandbox',
    '--quiet', '--only-categories=performance',
  ], { stdio: 'pipe' });

  const report = JSON.parse(fs.readFileSync(file, 'utf8'));
  const audit = report.audits;
  const metric = (id) => Math.round((audit[id].score ?? 0) * 100);

  reports.push({
    score: Math.round((report.categories.performance.score || 0) * 100),
    fcp: metric('first-contentful-paint'),
    lcp: metric('largest-contentful-paint'),
    tbt: metric('total-blocking-time'),
    cls: metric('cumulative-layout-shift'),
    si: metric('speed-index'),
    display: {
      fcp: audit['first-contentful-paint'].displayValue,
      lcp: audit['largest-contentful-paint'].displayValue,
      tbt: audit['total-blocking-time'].displayValue,
      cls: audit['cumulative-layout-shift'].displayValue,
      si: audit['speed-index'].displayValue,
    },
  });

  fs.unlinkSync(file);
}

const scores = reports.map((item) => item.score).sort((a, b) => a - b);
const median = scores[Math.floor(scores.length / 2)];
const worst = scores[0];

reports.forEach((item, index) => {
  console.log('прогон ' + (index + 1) + ': ' + item.score + ' (FCP ' + item.display.fcp
    + ', LCP ' + item.display.lcp + ', TBT ' + item.display.tbt + ', CLS ' + item.display.cls + ', SI ' + item.display.si + ')');
});

console.log('медиана: ' + median + ', худший: ' + worst);

if (median < 100 || worst < 95) {
  console.error('FAIL: Performance ' + worst + '…' + median + ', нужна 100 в медиане и не ниже 95 в худшем прогоне');
  process.exit(1);
}

console.log('OK: Performance 100');
