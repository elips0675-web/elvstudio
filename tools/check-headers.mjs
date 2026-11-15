import { readFile } from 'node:fs/promises';

const BASE = (process.env.ELV_BASE || 'http://127.0.0.1:8090').replace(/\/+$/, '');
const errors = [];

const pages = ['/', '/kit/', '/pricing/', '/blog/', '/modules/booking/'];
const required = {
  'x-frame-options': 'deny',
  'x-content-type-options': 'nosniff',
  'referrer-policy': '',
  'permissions-policy': '',
};

for (const path of pages) {
  const response = await fetch(BASE + path, {
    headers: { 'Accept-Encoding': 'gzip' },
    redirect: 'follow',
  });
  const headers = response.headers;
  const body = await response.arrayBuffer();

  if (response.status !== 200) {
    errors.push(`${path}: status ${response.status}`);
  }

  const type = (headers.get('content-type') || '').toLowerCase();

  if (!type.includes('text/html')) {
    errors.push(`${path}: content-type ${type}`);
  }

  if ((headers.get('content-encoding') || '').toLowerCase() !== 'gzip') {
    errors.push(`${path}: not gzipped`);
  }

  for (const [name, expected] of Object.entries(required)) {
    const value = (headers.get(name) || '').toLowerCase();

    if (!value) {
      errors.push(`${path}: ${name} missing`);
    } else if (expected && value !== expected) {
      errors.push(`${path}: ${name}=${value}`);
    }
  }

  const csp = (headers.get('content-security-policy') || '').toLowerCase();

  if (!csp) {
    errors.push(`${path}: CSP missing`);
  } else {
    for (const token of ["default-src 'self'", "frame-ancestors 'none'", "object-src 'none'"]) {
      if (!csp.includes(token)) {
        errors.push(`${path}: CSP without ${token}`);
      }
    }

    if (!/nonce-[a-z0-9+/=_-]+/.test(csp)) {
      errors.push(`${path}: CSP without nonce`);
    }
  }

  if (headers.get('x-powered-by')) {
    errors.push(`${path}: x-powered-by leaks`);
  }

  console.log(`${path} -> ${response.status} ${body.byteLength} bytes gzip`);
}

const assets = [
  ['/themes/elv-studio/assets/img/favicon.svg', 'image/svg+xml'],
  ['/themes/elv-studio/assets/img/og-default.png', 'image/png'],
];

for (const [path, type] of assets) {
  const response = await fetch(BASE + path);
  const actual = (response.headers.get('content-type') || '').toLowerCase();

  if (response.status !== 200 || !actual.includes(type)) {
    errors.push(`${path}: ${response.status} ${actual}`);
  }
}

const vercel = JSON.parse(await readFile(new URL('../vercel.json', import.meta.url), 'utf8'));
const globalHeaders = (vercel.headers || []).find((entry) => entry.source === '/(.*)')?.headers || [];
const robotsHeader = globalHeaders.find((header) => String(header.key).toLowerCase() === 'x-robots-tag');

if (robotsHeader && /noindex/i.test(String(robotsHeader.value))) {
  errors.push('vercel.json: X-Robots-Tag blocks the indexed demo');
}

const staticRobots = await readFile(new URL('../demo-static/robots.txt', import.meta.url), 'utf8');

if (!/User-agent:\s*\*/i.test(staticRobots) || !/^Allow:\s*\/\s*$/im.test(staticRobots) || /^Disallow:\s*\/\s*$/im.test(staticRobots)) {
  errors.push('demo-static/robots.txt: demo must stay indexable');
}

if (errors.length) {
  console.error(`\n${errors.length} header issue(s):`);

  for (const error of errors) {
    console.error('  x ' + error);
  }

  process.exit(1);
}

console.log('\nOK');
