#!/usr/bin/env node
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const outDir = path.join(root, 'test/ai-chat');

const git = (...args) => execFileSync('git', args, { cwd: root, encoding: 'utf8' }).trim();
const commit = git('rev-parse', '--short', 'HEAD');
const date = git('log', '-1', '--format=%cd', '--date=format:%d.%m.%Y');

const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8').replace(/\r\n/g, '\n');

const tracked = execFileSync('git', ['-c', 'core.quotepath=false', 'ls-files'], {
  cwd: root,
  encoding: 'utf8',
})
  .trim()
  .split('\n')
  .filter(Boolean);

const journal = read('Что сделано.txt');
const stage = (journal.match(/^\(Stage (\d+) -/m) || [])[1] || '?';
const stamp = 'Срез: ' + date + '. Stage ' + stage + '. Коммит: `' + commit + '`.';

const demoStaticHtml = tracked.filter((file) => file.startsWith('demo-static/') && file.endsWith('.html')).length;

const groups = [
  {
    file: '03-config.txt',
    title: 'Конфигурация: сборка, зависимости и тема',
    files: [
      'composer.json',
      'package.json',
      'themes/elv-studio/theme.yaml',
      'plugins/elv/kit/updates/version.yaml',
      'README.md',
    ],
  },
  {
    file: '04-plugin.php.txt',
    title: 'Плагин: точка входа Elv.Kit',
    files: ['plugins/elv/kit/Plugin.php'],
  },
  {
    file: '05-chat.txt',
    title: 'Консультант: ответы, фильтр, контроллер и виджет',
    files: [
      'plugins/elv/kit/classes/Chat.php',
      'plugins/elv/kit/classes/TextFilter.php',
      'plugins/elv/kit/classes/Http/ChatController.php',
      'plugins/elv/kit/assets/widget.js',
    ],
  },
  {
    file: '06-search.txt',
    title: 'Поиск: сервер, выдача и страница /search/',
    files: [
      'plugins/elv/kit/classes/Search.php',
      'themes/elv-studio/pages/search.htm',
      'themes/elv-studio/assets/js/search.js',
      'themes/elv-studio/assets/css/search.css',
      'themes/elv-studio/layouts/default.htm',
    ],
  },
  {
    file: '07-sitemap-seo.txt',
    title: 'Карта сайта и SEO-разметка',
    files: [
      'themes/elv-studio/pages/sitemap.htm',
      'plugins/elv/kit/classes/Seo.php',
      'plugins/elv/kit/classes/Sitemap.php',
      'plugins/elv/kit/routes.php',
    ],
  },
  {
    file: '08-checks-docs.txt',
    title: 'Проверки, доки и аудит приватности',
    files: [
      'tools/chat-check.php',
      'tools/search-check.php',
      'tools/seo-check.php',
      'tools/booking-check.php',
      'tools/check-php.mjs',
      'tools/check-seo.mjs',
      'tools/check-headers.mjs',
      'plugins/elv/kit/classes/SecurityHeaders.php',
      'docs/search.md',
      'docs/content-policy.md',
      'docs/00-audit-documents.md',
      'tools/price-check.php',
      'docs/prices.md',
    ],
  },
];

const missing = groups
  .flatMap((group) => group.files)
  .filter((file) => !fs.existsSync(path.join(root, file)));

if (missing.length) {
  console.error('missing files:\n- ' + missing.join('\n- '));
  process.exit(1);
}

const structure = () => {
  const sections = [
    { title: 'Корень', match: (file) => !file.includes('/') },
    { title: 'plugins/elv/kit', match: (file) => file.startsWith('plugins/elv/kit/') },
    { title: 'themes/elv-studio', match: (file) => file.startsWith('themes/elv-studio/') },
    { title: 'tools', match: (file) => file.startsWith('tools/') },
    { title: 'docs', match: (file) => file.startsWith('docs/') },
    { title: 'database/migrations', match: (file) => file.startsWith('database/') },
    {
      title: 'test',
      match: (file) => file === 'test/README.md' || file === 'test/Для анализа.txt',
    },
  ];

  const lines = [
    '# Структура репозитория Elv Landing',
    '',
    stamp,
    '',
    'Отслеживаемые файлы (`git ls-files`), всего ' + tracked.length + '.',
    '`demo-static` — ' + demoStaticHtml + ' HTML-страниц, показан не развёрнуто.',
    '`vendor/`, `modules/`, `.env` и дампы БД не показаны.',
  ];

  for (const section of sections) {
    const files = tracked.filter(section.match).sort();

    lines.push('', '## ' + section.title, '');

    if (!files.length) {
      lines.push('(пусто)');
      continue;
    }

    files.forEach((file) => lines.push('- `' + file + '`'));
  }

  lines.push('- `test/ai-chat/` — снимок кода для внешнего аудита (Stage ' + stage + ', собран `node tools/build-test-snapshot.mjs`)');

  return lines.join('\n') + '\n';
};

const write = (name, content) => {
  fs.writeFileSync(path.join(outDir, name), content, 'utf8');
  console.log(name + ': ' + Buffer.byteLength(content) + ' bytes');
};

write('02-structure.txt', structure());

for (const group of groups) {
  const body = group.files
    .map((file) => '===== FILE: ' + file + ' =====\n\n' + read(file).replace(/\s+$/, '') + '\n')
    .join('\n');

  write(group.file, '# ' + group.title + '\n\n' + stamp + '\n\n' + body);
}

const names = ['01-context.txt', '02-structure.txt', ...groups.map((group) => group.file)];
const separator = '='.repeat(78);

const parts = names.map((name) => {
  const content = fs.readFileSync(path.join(outDir, name), 'utf8').replace(/\s+$/, '');

  return separator + '\n' + name + '\n' + separator + '\n\n' + content;
});

const header = [
  '# Elv Landing — ALL-IN-ONE для AI-анализа',
  '',
  stamp,
  '',
  'Внутри: контекст, структура, конфигурация, Консультант, поиск, карта сайта,',
  'проверки, доки и аудит приватности. Файлы разделены, чтобы каждый был понятен.',
  '',
  'Состав:',
  ...names.map((name) => '- ' + name),
].join('\n');

write('ALL-IN-ONE.txt', header + '\n\n' + parts.join('\n\n') + '\n');
