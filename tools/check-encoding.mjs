/**
 * check-encoding — в файлах плагина и темы нет невидимых символов, которые
 * ломают вёрстку и разбор файлов.
 *
 * BOM (U+FEFF) в начале partial — не «пустой» символ: когда partial вставляют
 * внутрь CSS-grid, он становится анонимным grid-элементом и сдвигает все
 * карточки на одну ячейку. Именно так «уехала» сетка «Всё для приёма клиентов»
 * на главной. Zero-width space (U+200B) даёт тот же эффект и не виден в
 * редакторе. Входит в `gate.mjs`.
 */

import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const roots = ['plugins/elv/kit', 'themes/elv-business'];
const extensions = new Set(['.php', '.htm', '.html', '.yaml', '.yml', '.js', '.css', '.json', '.md', '.txt']);
const invisible = [
  { code: 0xfeff, name: 'BOM (U+FEFF)' },
  { code: 0x200b, name: 'zero-width space (U+200B)' },
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

    invisible.forEach(({ code, name }) => {
      let index = source.indexOf(String.fromCharCode(code));

      while (index !== -1) {
        const line = source.slice(0, index).split('\n').length;

        problems.push(relative + ':' + line + ' — ' + name);
        index = source.indexOf(String.fromCharCode(code), index + 1);
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

if (problems.length) {
  problems.forEach((problem) => console.error('FAIL: ' + problem));
  process.exit(1);
}

console.log('проверено ' + scanned + ' файлов плагина и темы: невидимых символов нет');
