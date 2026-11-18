import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const problems = [];

const PHP_PROBE = 'echo (int) (extension_loaded("zip") && extension_loaded("mbstring"));';

function phpSupports(requested) {
  const probe = spawnSync(requested, ['-r', PHP_PROBE], { encoding: 'utf8', stdio: 'pipe' });

  return probe.status === 0 && String(probe.stdout).trim() === '1';
}

function resolvePhp() {
  if (process.env.PHP_BIN) {
    return process.env.PHP_BIN;
  }

  const candidates = ['php'];
  const laragon = 'C:\\laragon\\bin\\php';

  if (fs.existsSync(laragon)) {
    fs.readdirSync(laragon)
      .map((name) => path.join(laragon, name, 'php.exe'))
      .filter((file) => fs.existsSync(file))
      .sort()
      .reverse()
      .forEach((file) => candidates.push(file));
  }

  return candidates.find(phpSupports) || '';
}

const readJson = (relative) => {
  const file = path.join(root, relative);

  if (!fs.existsSync(file)) {
    problems.push(relative + ' — файла нет');
    return null;
  }

  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    problems.push(relative + ' — невалидный JSON: ' + error.message);
    return null;
  }
};

const expected = [
  ['plugins/elv/kit/composer.json', 'october-plugin', 'elv/kit'],
  ['themes/elv-studio/composer.json', 'october-theme', 'elv/elv-studio'],
];

for (const [relative, type, name] of expected) {
  const json = readJson(relative);

  if (!json) {
    continue;
  }

  if (json.type !== type) {
    problems.push(relative + ' — type «' + json.type + '», ожидался «' + type + '»');
  }

  if (json.name !== name) {
    problems.push(relative + ' — name «' + json.name + '», ожидался «' + name + '»');
  }

  if (!json.description || String(json.description).trim().length < 20) {
    problems.push(relative + ' — пустое или короткое description');
  }

  if (json.license !== 'proprietary') {
    problems.push(relative + ' — license «' + json.license + '», ожидалась «proprietary»');
  }

  if (!json.require || !json.require.php) {
    problems.push(relative + ' — не указана требуемая версия PHP');
  }
}

const screenshot = path.join(root, 'themes/elv-studio/screenshot.png');

if (!fs.existsSync(screenshot)) {
  problems.push('themes/elv-studio/screenshot.png — файла нет');
} else {
  const head = fs.readFileSync(screenshot).subarray(0, 24);

  if (head.toString('ascii', 1, 4) !== 'PNG') {
    problems.push('themes/elv-studio/screenshot.png — не PNG');
  } else {
    const width = head.readUInt32BE(16);
    const height = head.readUInt32BE(20);

    if (width < 600 || height < 400) {
      problems.push('themes/elv-studio/screenshot.png — ' + width + 'x' + height + ', мелкий для карточки темы');
    }
  }
}

const versions = (relative) => {
  const file = path.join(root, relative);

  if (!fs.existsSync(file)) {
    return null;
  }

  const found = [...fs.readFileSync(file, 'utf8').matchAll(/^([0-9]+(?:\.[0-9]+)*)\s*:/gm)]
    .map((match) => match[1]);

  return found.length ? found : null;
};

const pluginVersions = versions('plugins/elv/kit/updates/version.yaml');
const themeVersions = versions('themes/elv-studio/version.yaml');

if (!pluginVersions) {
  problems.push('plugins/elv/kit/updates/version.yaml — нет ни одной версии');
}

if (!themeVersions) {
  problems.push('themes/elv-studio/version.yaml — нет ни одной версии');
}

function inspectArchives(php) {
  if (!php) {
    return null;
  }

  let output = '';

  try {
    output = execFileSync(php, ['tools/package-inspect.php'], {
      cwd: root,
      encoding: 'utf8',
      maxBuffer: 8 * 1024 * 1024,
    });
  } catch (error) {
    problems.push('не удалось собрать архивы: ' + String(error.stderr || error.message).trim().split('\n')[0]);

    return null;
  }

  try {
    return JSON.parse(output);
  } catch (error) {
    problems.push('разбор архивов вернул не JSON: ' + error.message.slice(0, 120) + ' | начало вывода: ' + JSON.stringify(output.slice(0, 80)));

    return null;
  }}

const newest = (list) => list.reduce((max, item) => (compareVersions(item, max) > 0 ? item : max), '0.0.0');

function compareVersions(a, b) {
  const left = a.split('.').map(Number);
  const right = b.split('.').map(Number);

  for (let index = 0; index < Math.max(left.length, right.length); index++) {
    const diff = (left[index] ?? 0) - (right[index] ?? 0);

    if (diff !== 0) {
      return diff;
    }
  }

  return 0;
}

if (pluginVersions && themeVersions) {
  const php = resolvePhp();
  let codeVersion = '';

  if (!php) {
    problems.push('PHP с расширениями zip и mbstring не найден (PHP_BIN или C:\\laragon\\bin\\php) — версию и архивы не проверить');
  } else {
    try {
      codeVersion = execFileSync(
        php,
        ['-r', 'require "plugins/elv/kit/classes/DownloadService.php"; echo Elv\\Kit\\Classes\\DownloadService::version();'],
        { cwd: root, encoding: 'utf8' }
      ).trim();
    } catch (error) {
      problems.push('не удалось получить версию из DownloadService: ' + error.message.split('\n')[0]);
    }
  }

  const newestPlugin = newest(pluginVersions);

  if (codeVersion && codeVersion !== newestPlugin) {
    problems.push('DownloadService::version() = ' + codeVersion + ', а updates/version.yaml — ' + newestPlugin);
  }

  const hardcoded = fs.readFileSync(path.join(root, 'plugins/elv/kit/classes/DownloadService.php'), 'utf8');

  if (/const\s+VERSION\s*=/.test(hardcoded)) {
    problems.push('plugins/elv/kit/classes/DownloadService.php — версия снова захардкожена константой');
  }

  const report = inspectArchives(php);

  if (report) {
    const product = report.product;
    const demo = report.demo;

    if (product.seeds.length) {
      problems.push('товарный архив содержит демо-сиды: ' + product.seeds.join(', '));
    }

    if (product.versionFileSeeds) {
      problems.push('товарный архив: в updates/version.yaml остались seed_kit_*.php');
    }

    if (!demo.seeds.length) {
      problems.push('демо-архив без демо-сидов — вариант --demo ничего не добавляет');
    }

    if (!product.hasComposer || !demo.hasComposer) {
      problems.push('в архиве нет composer.json плагина или темы');
    }

    if (!product.hasScreenshot) {
      problems.push('в товарном архиве нет themes/elv-studio/screenshot.png');
    }

    if (product.path === demo.path) {
      problems.push('товарный и демо-архив пишутся в один файл');
    }

    for (const [name, archive] of [['товарный', product], ['демо', demo]]) {
      if (!archive.listed.length) {
        problems.push(name + ' архив: в version.yaml не найдено ни одной миграции — сверка файлов ничего не проверила');
      }

      if (archive.missingListed.length) {
        problems.push(name + ' архив: version.yaml ссылается на отсутствующие файлы — ' + archive.missingListed.join(', '));
      }
    }
  }
}

if (problems.length) {
  problems.forEach((problem) => console.error('FAIL: ' + problem));
  process.exit(1);
}

console.log('plugin ' + newest(pluginVersions) + ', theme ' + newest(themeVersions) + ', composer.json и screenshot на месте');
