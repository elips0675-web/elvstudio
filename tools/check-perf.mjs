/**
 * check-perf — Lighthouse для страницы и порог «все метрики 100».
 *
 * Запуск: node tools/check-perf.mjs [url] [--runs=3]
 *
 * По умолчанию — мобильный профиль (как в PageSpeed): FCP/LCP/TBT/CLS/SI должны
 * дать 100. Холодный первый прогон после старта стенда не считается: скрипт
 * делает прогревный запрос и меряет `--runs` раз, берёт медиану. Нужен Chrome и
 * сеть для `npx lighthouse` (в CI не заведён, как `check:console`).
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const args = process.argv.slice(2);
const runsArg = args.find((arg) => arg.startsWith('--runs='));
const runs = Math.max(1, Number(runsArg ? runsArg.split('=')[1] : 3));
const url = (args.find((arg) => !arg.startsWith('--')) || 'http://127.0.0.1:8090/blog/').replace(/\/$/, '/');

const npx = process.platform === 'win32'
  ? path.join(path.dirname(process.execPath), 'node_modules', 'npm', 'bin', 'npx-cli.js')
  : 'npx';

await fetch(url).then((response) => response.text()).catch(() => {
  console.error('FAIL: ' + url + ' не отвечает — поднимите стенд');
  process.exit(1);
});

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
    + ', LCP ' + item.display.lcp + ', TBT ' + item.tbt + ', CLS ' + item.cls + ', SI ' + item.display.si + ')');
});

console.log('медиана: ' + median + ', худший: ' + worst);

if (median < 100 || worst < 95) {
  console.error('FAIL: Performance ' + worst + '…' + median + ', нужна 100 в медиане и не ниже 95 в худшем прогоне');
  process.exit(1);
}

console.log('OK: Performance 100');
