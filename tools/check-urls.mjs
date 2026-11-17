import { BASE, fetchPage, normalizePath } from './check-common.mjs';

const errors = [];
const seen = new Map();
const queue = ['/'];
const skip = /^(\/admin|\/download\/|\/subscribe\/confirm\/|\/unsubscribe\/|\/api\/)/;

const critical = [
  '/',
  '/kit/',
  '/modules/',
  '/modules/booking/',
  '/modules/shop/',
  '/modules/feedback/',
  '/modules/blog/',
  '/services/',
  '/services/hosting/',
  '/services/support/',
  '/services/plugins/',
  '/services/booking/',
  '/services/audit/',
  '/services/seo/',
  '/calculator/',
  '/pricing/',
  '/demo/',
  '/docs/',
  '/blog/',
  '/blog/tech/',
  '/blog/why-october-cms/',
  '/blog/booking-without-race/',
  '/blog/seo-for-google-ai/',
  '/blog/kak-vybrat-seo-studiyu/',
  '/blog/seo-audit-sajta/',
  '/blog/dogovor-na-razrabotku-sajta/',
  '/blog/migraciya-s-tilda-na-october-cms/',
  '/blog/sajt-dlya-kafe/',
  '/blog/plugin-october-cms/',
  '/blog/testirovanie-form-i-oplaty/',
  '/blog/logi-i-monitoring-october-cms/',
  '/blog/kak-popast-v-otvety-chatgpt/',
  '/blog/llms-txt/',
  '/blog/chto-ai-poisk-beret-v-otvety/',
  '/blog/ab-testy-na-malom-trafike/',
  '/blog/email-posle-zayavki/',
  '/blog/teplovye-karty-i-privatnost/',
  '/blog/sajt-dlya-avtoservisa/',
  '/blog/sajt-dlya-kliniki/',
  '/order/',
  '/booking/',
  '/feedback/',
  '/contacts/',
  '/terms/',
  '/privacy/',
  '/sitemap/',
  '/sitemap.xml',
  '/robots.txt',
  '/llms.txt',
  '/rss.xml',
];

function collectLinks(html) {
  return [...html.matchAll(/<a\b[^>]*href="([^"]+)"/gi)].map((match) => match[1]);
}

function internal(href) {
  if (!href || /^(#|mailto:|tel:|javascript:|data:)/i.test(href)) {
    return null;
  }

  let path = href;

  if (/^https?:\/\//i.test(href)) {
    if (!href.startsWith(BASE)) {
      return null;
    }

    path = new URL(href).pathname;
  }

  if (!path.startsWith('/')) {
    return null;
  }

  return normalizePath(path);
}

async function visit(path) {
  const page = await fetchPage(path);

  if (page.status !== 200) {
    errors.push(`${path} -> ${page.status}`);
    return;
  }

  if (!page.type.includes('text/html')) {
    return;
  }

  for (const href of collectLinks(page.body)) {
    const target = internal(href);

    if (!target || skip.test(target) || seen.has(target)) {
      continue;
    }

    seen.set(target, path);
    queue.push(target);
  }
}

await visit('/');
seen.set('/', '/');

const sitemap = await fetchPage('/sitemap.xml');

if (sitemap.status !== 200) {
  errors.push(`sitemap.xml -> ${sitemap.status}`);
} else {
  for (const match of sitemap.body.matchAll(/<loc>([^<]+)<\/loc>/g)) {
    const target = internal(match[1]);

    if (!target || skip.test(target) || seen.has(target)) {
      continue;
    }

    seen.set(target, '/sitemap.xml');
    queue.push(target);
  }
}

while (queue.length) {
  const path = queue.shift();
  await visit(path);
}

for (const path of critical) {
  if (seen.has(normalizePath(path))) {
    continue;
  }

  const page = await fetchPage(path);

  if (page.status !== 200) {
    errors.push(`critical ${path} -> ${page.status}`);
  }
}

const missing = await fetchPage('/definitely-missing-' + Date.now() + '/');

if (missing.status !== 404) {
  errors.push(`unknown page must return 404, got ${missing.status}`);
}

const home = await fetchPage('/');

function collectAnchorLinks(html) {
  return [...html.matchAll(/<a\b[^>]*href="([^"]+)"/gi)].map((match) => match[1]);
}

for (const href of collectAnchorLinks(home.body)) {
  const path = internal(href);

  if (path && !seen.has(path) && !skip.test(path)) {
    errors.push(`home link not crawled: ${path}`);
  }
}

console.log(`crawled: ${seen.size} internal URLs`);

if (errors.length) {
  console.error(`\n${errors.length} broken link(s):`);

  for (const error of errors) {
    console.error('  x ' + error);
  }

  process.exit(1);
}

console.log('\nOK');
