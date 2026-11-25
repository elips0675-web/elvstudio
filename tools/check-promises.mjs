/**
 * check-promises — в поставляемых текстах нет обещаний несуществующих функций.
 *
 * Покупатель читает витрину и админку как описание товара. Упоминание корзины,
 * личного кабинета, BM25-поиска или REST API — обещание, которого в коде нет:
 * такой текст дороже молчания, потому что ведёт к возврату. Проверка ищет
 * только точные формулировки, чтобы не задевать статьи блога (там корзина —
 * обычное слово про чужой магазин) и ключевые слова консультанта.
 *
 * Входит в `gate.mjs`. Запуск: node tools/check-promises.mjs
 */

import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');

const roots = [
  'themes/elv-business/pages',
  'themes/elv-business/partials',
  'plugins/elv/kit/classes',
  'plugins/elv/kit/views',
  'plugins/elv/kit/models',
];

const extensions = new Set(['.htm', '.html', '.php', '.yaml']);

// README уезжает в архив покупателю — проверяем отдельным файлом.
const extraFiles = ['README.md'];

const rules = [
  {
    pattern: /cabinet_url/i,
    hint: 'плейсхолдер личного кабинета',
    scope: null,
  },
  {
    pattern: /\bBM25\b/i,
    hint: 'поиск BM25',
    scope: null,
  },
  {
    pattern: /\bREST API\b/i,
    hint: 'REST API',
    scope: null,
  },
  {
    pattern: /корзин[аеуыой]/i,
    hint: 'корзина — модуля корзины в продукте нет',
    // `Chat.php` держит «корзин» как ключевое слово распознавания запроса,
    // а не как обещание. Остальные классы и модели проверяются.
    scope: (relative) => !relative.startsWith('plugins/elv/kit/classes/')
      && !relative.startsWith('plugins/elv/kit/models/'),
  },
];

const problems = [];
let scanned = 0;

const checkFile = (file) => {
  const relative = path.relative(root, file).replace(/\\/g, '/');
  const source = fs.readFileSync(file, 'utf8');

  scanned += 1;

  rules.forEach(({ pattern, hint, scope }) => {
    if (scope && !scope(relative)) {
      return;
    }

    const match = pattern.exec(source);

    if (match) {
      const line = source.slice(0, match.index).split('\n').length;

      problems.push(relative + ':' + line + ' — ' + hint + ' «' + match[0] + '»');
    }
  });
};

const walk = (dir) => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);

    if (entry.isDirectory()) {
      walk(full);
      continue;
    }

    if (extensions.has(path.extname(entry.name))) {
      checkFile(full);
    }
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

extraFiles.forEach((relative) => {
  const file = path.join(root, relative);

  if (fs.existsSync(file)) {
    checkFile(file);
  }
});

if (problems.length) {
  problems.forEach((problem) => console.error('FAIL: ' + problem));
  process.exit(1);
}

console.log('проверено ' + scanned + ' файлов: обещаний несуществующих функций нет');
