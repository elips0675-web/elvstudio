import { gzipSync } from 'node:zlib';

const BASE = (process.env.ELV_BASE || 'http://127.0.0.1:8090').replace(/\/+$/, '');
const KB = 1024;
const budgets = {
  html: 150 * KB,
  css: 36 * KB,
  js: 20 * KB,
  gzip: 30 * KB,
};
const pages = ['/', '/kit/', '/pricing/', '/calculator/', '/services/', '/services/seo/', '/blog/', '/blog/kak-vybrat-seo-studiyu/', '/blog/sajt-dlya-kafe/', '/blog/plugin-october-cms/', '/blog/kak-popast-v-otvety-chatgpt/', '/blog/ab-testy-na-malom-trafike/', '/blog/sajt-dlya-avtoservisa/', '/blog/kejs-lcp-12-skriptov/', '/modules/booking/'];
const errors = [];

function kb(bytes) {
  return (bytes / KB).toFixed(1) + ' KB';
}

async function load(path) {
  const response = await fetch(BASE + path);
  const buffer = Buffer.from(await response.arrayBuffer());

  return buffer.toString('utf8');
}

for (const path of pages) {
  const html = await load(path);
  const cssParts = [...html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/gi)].map((match) => match[1]);
  const jsParts = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].map((match) => match[1]);
  const css = cssParts.reduce((sum, part) => sum + Buffer.byteLength(part, 'utf8'), 0);
  const js = jsParts.reduce((sum, part) => sum + Buffer.byteLength(part, 'utf8'), 0);
  const htmlBytes = Buffer.byteLength(html, 'utf8');
  const gzip = gzipSync(Buffer.from(html, 'utf8')).length;

  console.log(`${path.padEnd(22)} html ${kb(htmlBytes).padStart(8)}  css ${kb(css).padStart(8)}  js ${kb(js).padStart(8)}  gzip ${kb(gzip).padStart(8)}`);

  if (htmlBytes > budgets.html) {
    errors.push(`${path}: html ${htmlBytes} > ${budgets.html} bytes`);
  }

  if (css > budgets.css) {
    errors.push(`${path}: inline css ${css} > ${budgets.css} bytes`);
  }

  if (js > budgets.js) {
    errors.push(`${path}: inline js ${js} > ${budgets.js} bytes`);
  }

  if (gzip > budgets.gzip) {
    errors.push(`${path}: gzip ${gzip} > ${budgets.gzip} bytes`);
  }
}

if (errors.length) {
  console.error(`\n${errors.length} budget issue(s):`);

  for (const error of errors) {
    console.error('  x ' + error);
  }

  process.exit(1);
}

console.log('\nOK');
