export const BASE = (process.env.ELV_BASE || 'http://127.0.0.1:8090').replace(/\/+$/, '');

export async function fetchPage(path) {
  const url = /^https?:/.test(path) ? path : BASE + path;
  const response = await fetch(url, { redirect: 'follow' });

  return {
    url,
    status: response.status,
    type: (response.headers.get('content-type') || '').toLowerCase(),
    headers: response.headers,
    body: await response.text(),
  };
}

export function first(html, regex, group = 1) {
  const match = String(html || '').match(regex);

  return match ? match[group] || '' : '';
}

export function all(html, regex, group = 1) {
  return [...String(html || '').matchAll(regex)].map((match) => match[group] || '');
}

export function decode(value) {
  return String(value || '')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#0?39;/g, "'")
    .replace(/&nbsp;/g, ' ');
}

export function plain(value) {
  return decode(String(value || '').replace(/<[^>]+>/g, ' ')).replace(/\s+/g, ' ').trim();
}

export function normalizePath(path) {
  const value = String(path || '/').split('#')[0].split('?')[0];
  const prefixed = value.startsWith('/') ? value : '/' + value;

  return prefixed.length > 1 ? prefixed.replace(/\/+$/, '') : '/';
}

export function absolute(path) {
  return /^https?:/.test(path) ? path : BASE + (path.startsWith('/') ? path : '/' + path);
}

export function failList(errors, lines = []) {
  for (const line of lines) {
    console.log('  ' + line);
  }

  if (errors.length) {
    console.error('\n' + errors.length + ' issue(s):');

    for (const error of errors) {
      console.error('  x ' + error);
    }

    process.exit(1);
  }

  console.log('\nOK');
}
