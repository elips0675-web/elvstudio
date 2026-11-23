/**
 * check-iframe-demo — демо-стенд готов к показу внутри <iframe>.
 *
 * Запуск против стенда ниши:
 *   node tools/check-iframe-demo.mjs --base=https://booking.example.com \
 *     --parent=https://example.com
 *
 * Проверяется то, что ломает встраивание чаще всего: CSP `frame-ancestors`
 * без родительского домена, оставшийся `X-Frame-Options`, cookie сессии без
 * `SameSite=None` (тогда POST из iframe получает 419) и сама отправка формы.
 *
 * Стенд должен быть собран с `ELV_FRAME_ANCESTORS="<родитель>"`; для проверки
 * локального сервера запускайте его с этой переменной в окружении.
 */

const args = Object.fromEntries(
  process.argv.slice(2).map((arg) => {
    const [key, ...value] = arg.replace(/^--/, '').split('=');
    return [key, value.join('=') || 'true'];
  })
);

const base = String(args.base || process.env.ELV_DEMO_BASE || '').replace(/\/+$/, '');
const parent = String(args.parent || process.env.ELV_PARENT_ORIGIN || '').replace(/\/+$/, '');

if (!base) {
  console.error('Укажите адрес демо-стенда: --base=https://booking.example.com');
  process.exit(1);
}

if (!parent) {
  console.error('Укажите домен витрины, которая встраивает стенд: --parent=https://example.com');
  process.exit(1);
}

const errors = [];
const check = (condition, message) => {
  if (!condition) {
    errors.push(message);
  }
};

let response;

try {
  response = await fetch(base + '/', { redirect: 'follow' });
} catch (error) {
  console.error('Запрос не выполнен: ' + error.message);
  process.exit(1);
}

const html = await response.text();
check(response.status === 200, 'GET / вернул ' + response.status);

const csp = (response.headers.get('content-security-policy') || '').toLowerCase();
const frameAncestors = (csp.match(/frame-ancestors\s+([^;]+)/) || ['', ''])[1];
check(frameAncestors.includes(parent.toLowerCase()), 'CSP frame-ancestors не разрешает ' + parent);

const xfo = (response.headers.get('x-frame-options') || '').toLowerCase();
check(xfo === '', 'X-Frame-Options="' + xfo + '" заблокирует iframe');

const setCookies = typeof response.headers.getSetCookie === 'function'
  ? response.headers.getSetCookie()
  : (response.headers.get('set-cookie') ? [response.headers.get('set-cookie')] : []);

const jar = [];

for (const raw of setCookies) {
  const lower = raw.toLowerCase();
  jar.push(raw.split(';')[0].trim());

  if (lower.includes('session') || lower.includes('xsrf-token')) {
    check(lower.includes('samesite=none'), 'Кука сессии без SameSite=None — POST из iframe получит 419');

    if (base.startsWith('https://')) {
      check(lower.includes('secure'), 'Кука сессии без флага Secure');
    }
  }
}

const csrf = (html.match(/<meta\s+name="csrf-token"\s+content="([^"]+)"/i) || ['', ''])[1];

if (csrf !== '' && jar.length > 0) {
  const chat = await fetch(base + '/chat', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': csrf,
      Cookie: jar.join('; '),
      Origin: base,
      Referer: base + '/',
    },
    body: JSON.stringify({ message: 'какие услуги вы оказываете' }),
  });

  check(chat.status === 200, 'POST /chat вернул ' + chat.status + ' (ожидался 200, не 419)');
} else {
  errors.push('На странице нет csrf-token или cookie — отправку формы из iframe проверить нельзя');
}

if (errors.length) {
  errors.forEach((error) => console.error('  x ' + error));
  process.exit(1);
}

console.log('OK: ' + base + ' готов к работе внутри <iframe> на ' + parent);
