/**
 * check-brand — в поставляемых файлах нет чужих контактов и домена студии.
 *
 * Покупатель ставит плагин и тему у себя: домен `elv-studio.by` и адреса
 * `sales@elv-studio.by` / `t.me/elvstudio` в его админке и на его сайте —
 * утечка наших контактов и реклама, которую он не заказывал. Входит в `gate.mjs`.
 *
 * Вторая проверка — название бренда в шаблонах. Шапка, подвал, оферта и письма
 * берут имя из настроек (`brand_name`, `brand_subtitle`, `legal_name`): на чистой
 * установке покупатель видит «Elv Kit» и меняет имя в админке. Зашитое в шаблоне
 * «Elv Studio» — это наш бренд на чужом сайте.
 */

import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const roots = ['plugins/elv/kit', 'themes/elv-business'];
const extensions = new Set(['.php', '.htm', '.html', '.yaml', '.yml', '.js', '.css', '.json', '.md', '.txt']);
const forbidden = [
  { pattern: /elv-studio\.by/i, hint: 'домен студии' },
  { pattern: /sales@elv-studio/i, hint: 'почта студии' },
  { pattern: /t\.me\/elvstudio/i, hint: 'Telegram студии' },
];

const problems = [];
let scanned = 0;

const walk = (dir) => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);

    if (entry.isDirectory()) {
      walk(full);
      continue;
    }

    if (!extensions.has(path.extname(entry.name))) {
      continue;
    }

    scanned += 1;
    const source = fs.readFileSync(full, 'utf8');
    const relative = path.relative(root, full).replace(/\\/g, '/');

    forbidden.forEach(({ pattern, hint }) => {
      const match = pattern.exec(source);

      if (match) {
        const line = source.slice(0, match.index).split('\n').length;

        problems.push(relative + ':' + line + ' — ' + hint + ' «' + match[0] + '»');
      }
    });
  }
};

roots.forEach((relative) => {
  const dir = path.join(root, relative);

  if (!fs.existsSync(dir)) {
    problems.push(relative + ' — каталога нет');
    return;
  }

  walk(dir);
});

/*
 * Шаблоны, которые рендерит сайт покупателя. Имя бренда в них приходит из
 * настроек, а не из текста файла; «Elv Kit» допустим как значение по умолчанию.
 */
const templateRoots = [
  'themes/elv-business/layouts',
  'themes/elv-business/pages',
  'themes/elv-business/partials',
  'plugins/elv/kit/views',
];
const hardBrand = /Elv Studio/;
let templates = 0;

const walkTemplates = (dir) => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);

    if (entry.isDirectory()) {
      walkTemplates(full);
      continue;
    }

    if (!['.htm', '.html'].includes(path.extname(entry.name))) {
      continue;
    }

    templates += 1;
    const source = fs.readFileSync(full, 'utf8');
    const match = hardBrand.exec(source);

    if (match) {
      const relative = path.relative(root, full).replace(/\\/g, '/');
      const line = source.slice(0, match.index).split('\n').length;

      problems.push(
        relative + ':' + line + ' — бренд зашит в шаблон, возьмите elv_setting(\'brand_name\')'
      );
    }
  }
};

templateRoots.forEach((relative) => {
  const dir = path.join(root, relative);

  if (!fs.existsSync(dir)) {
    problems.push(relative + ' — каталога нет');
    return;
  }

  walkTemplates(dir);
});

/*
 * Письма: тема письма и текст — тоже лицо сайта покупателя. Бренд в них берётся
 * из настроек (`Settings::get('brand_name', 'Elv Kit')` в PHP, `elv_setting()`
 * в шаблоне), а не пишется строкой. «Elv Kit» допустим только как значение по
 * умолчанию рядом с `brand_name`.
 */
const mailRoots = [
  'plugins/elv/kit/classes/Mail',
  'plugins/elv/kit/views/mail',
];
const mailBrand = /Elv (?:Kit|Studio)/;
let mailFiles = 0;

const walkMail = (dir) => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);

    if (entry.isDirectory()) {
      walkMail(full);
      continue;
    }

    if (!['.php', '.htm', '.html'].includes(path.extname(entry.name))) {
      continue;
    }

    mailFiles += 1;
    const relative = path.relative(root, full).replace(/\\/g, '/');
    const lines = fs.readFileSync(full, 'utf8').split(/\r?\n/);

    lines.forEach((line, index) => {
      if (!mailBrand.test(line)) {
        return;
      }

      if (/brand_name|elv_setting\(/.test(line)) {
        return;
      }

      problems.push(
        relative + ':' + (index + 1) + ' — бренд зашит в письмо, возьмите brand_name из настроек'
      );
    });
  }
};

mailRoots.forEach((relative) => {
  const dir = path.join(root, relative);

  if (!fs.existsSync(dir)) {
    problems.push(relative + ' — каталога нет');
    return;
  }

  walkMail(dir);
});

if (problems.length) {
  problems.forEach((problem) => console.error('FAIL: ' + problem));
  process.exit(1);
}

console.log(
  'проверено ' + scanned + ' файлов плагина и темы: чужих контактов нет; ' +
    templates + ' шаблонов: бренд из настроек; ' +
    mailFiles + ' файлов писем: бренд из настроек'
);
