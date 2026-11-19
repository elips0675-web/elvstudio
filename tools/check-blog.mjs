/**
 * check-blog — у блога один адрес на страницу, у каждой страницы свой canonical.
 *
 * Проверяет: `/blog` уводится 301 на `/blog/`; страницы пагинации отдают свой
 * canonical `?page=N` и свой заголовок; за последней страницей — 404; страницы
 * с фильтрами (`?category=`) закрыты от индекса и указывают на общий список.
 * Входит в `gate.mjs`.
 */

const base = (process.env.BASE || process.argv[2] || 'http://127.0.0.1:8090').replace(/\/$/, '');
const errors = [];
const seenCanonicals = new Map();
const seenTitles = new Map();

const get = async (path) => {
  const response = await fetch(base + path, { redirect: 'manual' });
  const html = await response.text();

  return { status: response.status, location: response.headers.get('location') || '', html };
};

const titleOf = (html) => (/\<title>([\s\S]*?)<\/title>/i.exec(html) || ['', ''])[1].trim();
const canonicalOf = (html) => (/rel="canonical" href="([^"]+)"/i.exec(html) || ['', ''])[1];
const robotsOf = (html) => (/<meta name="robots" content="([^"]*)"/i.exec(html) || ['', ''])[1];

const noSlash = await get('/blog');

if (noSlash.status !== 301) {
  errors.push('/blog — статус ' + noSlash.status + ', ожидался 301 на /blog/');
} else if (!noSlash.location.endsWith('/blog/')) {
  errors.push('/blog — редирект на ' + noSlash.location + ', ожидался /blog/');
}

const first = await get('/blog/');

if (first.status !== 200) {
  errors.push('/blog/ — статус ' + first.status + ', ожидался 200');
}

const firstCanonical = canonicalOf(first.html);
const firstTitle = titleOf(first.html);

if (firstCanonical !== base + '/blog/') {
  errors.push('/blog/ — canonical ' + (firstCanonical || 'нет') + ', ожидался ' + base + '/blog/');
}

if (/noindex/i.test(robotsOf(first.html))) {
  errors.push('/blog/ — страница списка закрыта от индекса');
}

if (!firstTitle) {
  errors.push('/blog/ — пустой title');
}

seenCanonicals.set(firstCanonical, '/blog/');
seenTitles.set(firstTitle, '/blog/');

const pagination = /Страница\s+1\s+из\s+(\d+)/.exec(first.html);
const lastPage = pagination ? Number(pagination[1]) : 1;

for (let page = 2; page <= lastPage; page += 1) {
  const path = '/blog/?page=' + page;
  const result = await get(path);

  if (result.status !== 200) {
    errors.push(path + ' — статус ' + result.status + ', ожидался 200');
    continue;
  }

  const canonical = canonicalOf(result.html);
  const title = titleOf(result.html);

  if (canonical !== base + path) {
    errors.push(path + ' — canonical ' + (canonical || 'нет') + ', ожидался ' + base + path);
  }

  if (!title.includes('страница ' + page)) {
    errors.push(path + ' — title «' + title + '» без номера страницы');
  }

  if (!/rel="prev"/i.test(result.html)) {
    errors.push(path + ' — нет rel="prev"');
  }

  if (page < lastPage && !/rel="next"/i.test(result.html)) {
    errors.push(path + ' — нет rel="next"');
  }

  if (seenCanonicals.has(canonical)) {
    errors.push(path + ' — canonical совпадает с ' + seenCanonicals.get(canonical));
  }

  if (seenTitles.has(title)) {
    errors.push(path + ' — title совпадает с ' + seenTitles.get(title));
  }

  seenCanonicals.set(canonical, path);
  seenTitles.set(title, path);
}

const beyond = await get('/blog/?page=' + (lastPage + 1));

if (beyond.status !== 404) {
  errors.push('/blog/?page=' + (lastPage + 1) + ' — статус ' + beyond.status + ', ожидался 404');
}

const categoryHref = /href="[^"]*(\/blog\/?\?category=[a-z0-9-]+)"/i.exec(first.html);
const category = categoryHref ? categoryHref[1].replace('/blog?', '/blog/?') : '';

if (category === '') {
  errors.push('/blog/ — не нашёл ссылку на категорию для проверки фильтра');
} else {
  const filtered = await get(category);

  if (filtered.status !== 200) {
    errors.push(category + ' — статус ' + filtered.status + ', ожидался 200');
  }

  if (!/noindex/i.test(robotsOf(filtered.html))) {
    errors.push(category + ' — фильтр не закрыт от индекса (robots: ' + (robotsOf(filtered.html) || 'нет') + ')');
  }

  if (canonicalOf(filtered.html) !== base + '/blog/') {
    errors.push(category + ' — canonical ' + (canonicalOf(filtered.html) || 'нет') + ', ожидался ' + base + '/blog/');
  }
}

const sitemap = await get('/sitemap.xml');
const locations = [...sitemap.html.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => match[1].trim());
const duplicated = locations.filter((loc, index) => locations.indexOf(loc) !== index);

if (duplicated.length) {
  errors.push('sitemap.xml — повторяющиеся адреса: ' + [...new Set(duplicated)].slice(0, 3).join(', '));
}

if (errors.length) {
  errors.forEach((error) => console.error('FAIL: ' + error));
  process.exit(1);
}

console.log('блог: 301 на /blog/, ' + lastPage + ' страниц со своим canonical и title, '
  + '404 за последней, фильтр закрыт от индекса');
