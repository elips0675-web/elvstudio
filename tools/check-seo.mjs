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

  if (!/<meta\s+name="viewport"/i.test(html)) {
    errors.push(`${where}: viewport meta missing`);
  }

  for (const attrs of all(html, /<img\b([^>]*)>/gi)) {
    if (!/\balt\s*=/i.test(attrs)) {
      errors.push(`${where}: img without alt`);
    }
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
    let data;

    try {
      data = JSON.parse(block);
    } catch (error) {
      errors.push(`${where}: JSON-LD invalid (${error.message.slice(0, 60)})`);
      continue;
    }

    if (data['@context'] !== 'https://schema.org') {
      errors.push(`${where}: JSON-LD @context is not schema.org`);
    }

    const nodes = Array.isArray(data['@graph']) ? data['@graph'] : [data];

    if (!nodes.length) {
      errors.push(`${where}: JSON-LD graph is empty`);
    }

    for (const node of nodes) {
      const type = node && node['@type'];

      if (!type) {
        errors.push(`${where}: JSON-LD node without @type`);
        continue;
      }

      if (type === 'BlogPosting' && (!node.headline || !node.datePublished || !node.author || !node.image || !node.wordCount || !node.keywords || !node.articleSection)) {
        errors.push(`${where}: BlogPosting misses headline/datePublished/author/image/wordCount/keywords/articleSection`);
      }

      if (type === 'BreadcrumbList' && !(node.itemListElement || []).length) {
        errors.push(`${where}: BreadcrumbList is empty`);
      }

      if (type === 'FAQPage' && !(node.mainEntity || []).length) {
        errors.push(`${where}: FAQPage is empty`);
      }

      if (type === 'Organization' && (!node.name || !node.url || !node.logo)) {
        errors.push(`${where}: Organization misses name/url/logo`);
      }

      if (type === 'WebSite' && (!node.name || !node.url)) {
        errors.push(`${where}: WebSite misses name/url`);
      }

      if (['Product', 'SoftwareApplication'].includes(type) && (!node.name || !node.offers)) {
        errors.push(`${where}: ${type} misses name/offers`);
      }
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

const sitemapPage = await fetchPage('/sitemap/');
const anchorTargets = ['sitemap-site', 'sitemap-services', 'sitemap-modules', 'sitemap-buy', 'sitemap-legal', 'sitemap-blog'];

for (const target of anchorTargets) {
  if (!sitemapPage.body.includes(`id="${target}"`)) {
    errors.push(`/sitemap/: anchor target ${target} is missing`);
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
