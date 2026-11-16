import { BASE, fetchPage, first, all, plain, normalizePath } from './check-common.mjs';

const errors = [];
const sitemap = await fetchPage('/sitemap.xml');
const urls = all(sitemap.body, /<loc>([^<]+)<\/loc>/g).map((loc) => loc.trim());

if (!urls.length) {
  console.error('sitemap.xml is empty');
  process.exit(1);
}

const robotsTxt = (await fetchPage('/robots.txt')).body;

if (/^Disallow:\s*\/\s*$/im.test(robotsTxt)) {
  errors.push('/robots.txt: root disallow blocks the whole site');
}

const seen = new Set(urls.map((url) => normalizePath(new URL(url).pathname)));
const titles = new Map();
const descriptions = new Map();

for (const raw of urls) {
  const path = new URL(raw).pathname + (raw.endsWith('/') ? '' : '');
  const page = await fetchPage(path);
  const html = page.body;
  const where = normalizePath(path);

  if (page.status !== 200) {
    errors.push(`${where}: status ${page.status}`);
    continue;
  }

  if (!/<html[^>]*lang="ru"/i.test(html)) {
    errors.push(`${where}: <html lang="ru"> missing`);
  }

  const title = plain(first(html, /<title>([\s\S]*?)<\/title>/i));

  if (title.length < 10 || title.length > 75) {
    errors.push(`${where}: title length ${title.length}`);
  }

  if (titles.has(title)) {
    errors.push(`${where}: duplicate title with ${titles.get(title)}`);
  } else {
    titles.set(title, where);
  }

  const description = first(html, /<meta\s+name="description"\s+content="([^"]*)"/i);

  if (description.length < 50 || description.length > 300) {
    errors.push(`${where}: description length ${description.length}`);
  }

  if (descriptions.has(description)) {
    errors.push(`${where}: duplicate description with ${descriptions.get(description)}`);
  } else {
    descriptions.set(description, where);
  }

  const canonical = first(html, /<link\s+rel="canonical"\s+href="([^"]+)"/i);

  if (!canonical) {
    errors.push(`${where}: canonical missing`);
  } else {
    if (!canonical.startsWith(BASE)) {
      errors.push(`${where}: canonical points outside base: ${canonical}`);
    }

    if (normalizePath(new URL(canonical).pathname) !== where) {
      errors.push(`${where}: canonical path mismatch: ${canonical}`);
    }
  }

  const xRobots = (page.headers.get('x-robots-tag') || '').toLowerCase();

  if (/noindex|none/.test(xRobots)) {
    errors.push(`${where}: X-Robots-Tag blocks indexing (${xRobots})`);
  }

  const metaRobots = first(html, /<meta\s+name="robots"\s+content="([^"]*)"/i);

  if (!/index/i.test(metaRobots) || /noindex/i.test(metaRobots)) {
    errors.push(`${where}: meta robots is not indexable (${metaRobots || 'missing'})`);
  }

  if (!first(html, /<meta\s+property="og:title"\s+content="([^"]+)"/i)) {
    errors.push(`${where}: og:title missing`);
  }

  if (!first(html, /<meta\s+property="og:description"\s+content="([^"]+)"/i)) {
    errors.push(`${where}: og:description missing`);
  }

  const image = first(html, /<meta\s+property="og:image"\s+content="([^"]+)"/i);

  if (!image.startsWith('http')) {
    errors.push(`${where}: og:image missing or relative`);
  }

  if (!first(html, /<meta\s+name="twitter:card"\s+content="([^"]+)"/i)) {
    errors.push(`${where}: twitter:card missing`);
  }

  const h1 = all(html, /<h1\b[^>]*>([\s\S]*?)<\/h1>/gi);

  if (h1.length !== 1) {
    errors.push(`${where}: h1 count ${h1.length}`);
  }

  const jsonld = all(html, /<script[^>]*type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/gi);

  if (!jsonld.length) {
    errors.push(`${where}: JSON-LD missing`);
  }

  for (const block of jsonld) {
    try {
      JSON.parse(block);
    } catch (error) {
      errors.push(`${where}: JSON-LD invalid (${error.message.slice(0, 60)})`);
    }
  }
}

const assertSeo = [
  '/blog/',
  '/modules/',
  '/pricing/',
  '/kit/',
];

for (const path of assertSeo) {
  if (!seen.has(normalizePath(path))) {
    errors.push(`sitemap misses ${path}`);
  }
}

const imageUrl = first((await fetchPage('/')).body, /<meta\s+property="og:image"\s+content="([^"]+)"/i);
const imageResponse = await fetch(imageUrl);

if (imageResponse.status !== 200) {
  errors.push(`og:image -> ${imageResponse.status}`);
}

console.log(`checked: ${urls.length} sitemap URLs`);

if (errors.length) {
  console.error(`\n${errors.length} SEO issue(s):`);

  for (const error of errors) {
    console.error('  x ' + error);
  }

  process.exit(1);
}

console.log('\nOK');
