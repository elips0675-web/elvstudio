import { readFile, readdir } from 'node:fs/promises';
import { join, relative, extname, basename } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const THEME = join(ROOT, 'themes', 'elv-business');
const PLUGIN = join(ROOT, 'plugins', 'elv', 'kit');
const BASE = (process.env.ELV_BASE || 'http://127.0.0.1:8090').replace(/\/+$/, '');
const errors = [];

async function walk(directory, files = []) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    entry.isDirectory() ? await walk(path, files) : files.push(path);
  }

  return files;
}

async function source(path) {
  return readFile(path, 'utf8');
}

const pageFiles = (await readdir(join(THEME, 'pages'))).filter((name) => name.endsWith('.htm'));
const pageIds = new Set(pageFiles.map((name) => name.replace(/\.htm$/, '')));
const pageUrls = new Map();

for (const name of pageFiles) {
  const text = await source(join(THEME, 'pages', name));
  const head = text.split(/^==$/m)[0] || '';
  const url = (head.match(/^url\s*=\s*"([^"]+)"/m) || [])[1];
  pageUrls.set(name.replace(/\.htm$/, ''), url || null);
}

const sitemap = await (await fetch(BASE + '/sitemap.xml')).text();
const sitemapPaths = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((match) => new URL(match[1]).pathname.replace(/\/+$/, '') || '/');

const dynamic = new Set(['module', 'blog-post', 'order-success']);
const staticUrls = new Set();
const noindexUrls = new Set();

for (const [id, url] of pageUrls) {
  if (id === '404') {
    continue;
  }

  const head = (await source(join(THEME, 'pages', id + '.htm'))).split(/^==$/m)[0] || '';
  const noindex = /^\s*meta_robots\s*=\s*"[^"]*noindex/m.test(head);
  const normalized = url ? url.replace(/\/+$/, '') || '/' : null;

  if (normalized && !url.includes(':')) {
    (noindex ? noindexUrls : staticUrls).add(normalized);
  }

  if (!url) {
    errors.push(`page ${id} has no url in front matter`);
  }
}

for (const url of staticUrls) {
  if (!sitemapPaths.includes(url)) {
    errors.push(`page url ${url} missing from sitemap`);
  }
}

for (const url of noindexUrls) {
  if (sitemapPaths.includes(url)) {
    errors.push(`page url ${url} is noindex but present in sitemap`);
  }
}

for (const path of sitemapPaths) {
  if (staticUrls.has(path)) {
    continue;
  }

  const parent = '/' + path.split('/').filter(Boolean)[0];

  if (pageUrls.get('module') && path.startsWith('/modules/')) {
    continue;
  }

  if (pageUrls.get('blog-post') && path.startsWith('/blog/') && path !== '/blog') {
    continue;
  }

  if (!pageUrls.has(path.slice(1))) {
    errors.push(`sitemap url ${path} has no page file`);
  }
}

const themeSources = (await walk(THEME)).filter((path) => ['.htm', '.css', '.js', '.php', '.json'].includes(extname(path)));
const pluginSources = (await walk(PLUGIN)).filter((path) => ['.php', '.htm'].includes(extname(path)));
const sources = new Map();

for (const path of [...themeSources, ...pluginSources]) {
  sources.set(path, await source(path));
}

const joined = [...sources.values()].join('\n');
const partialFiles = (await readdir(join(THEME, 'partials'))).filter((name) => name.endsWith('.htm'));

for (const file of partialFiles) {
  const name = file.replace(/\.htm$/, '');

  if (!new RegExp(`partial\\s+['"]${name}['"]`).test(joined)) {
    errors.push(`unused partial: ${name}`);
  }
}

for (const [id] of pageUrls) {
  if (dynamic.has(id) || id === 'index') {
    continue;
  }

  if (
    !new RegExp(`['"]${id}['"]\\s*\\|\\s*page`).test(joined) &&
    !new RegExp(`url\\(\\s*['"]\\/${id}\\/?['"]\\s*\\)`).test(joined) &&
    !new RegExp(`\\|\\s*page\\(`).test(joined)
  ) {
    errors.push(`page ${id} is never linked`);
  }
}

const referenced = (name) => joined.includes(name);

const assetFiles = (await walk(join(THEME, 'assets'))).filter((path) => {
  const extension = extname(path);

  return ['.svg', '.png', '.jpg', '.jpeg', '.webp', '.woff', '.woff2', '.ico'].includes(extension);
});

for (const path of assetFiles) {
  const name = basename(path);

  if (!referenced(name) && !referenced(relative(THEME, path).replace(/\\/g, '/'))) {
    errors.push(`unused asset: ${relative(THEME, path)}`);
  }
}

const pageFilterRefs = [...joined.matchAll(/['"]([a-z0-9-]+)['"]\s*\|\s*page/g)].map((match) => match[1]);

for (const id of new Set(pageFilterRefs)) {
  if (!pageIds.has(id)) {
    errors.push(`|page('${id}') references missing page`);
  }
}

console.log(`pages ${pageIds.size}, partials ${partialFiles.length}, assets ${assetFiles.length}`);

if (errors.length) {
  console.error(`\n${errors.length} orphan issue(s):`);

  for (const error of errors) {
    console.error('  x ' + error);
  }

  process.exit(1);
}

console.log('\nOK');
