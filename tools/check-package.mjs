import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const problems = [];

function resolvePhp() {
  if (process.env.PHP_BIN) {
    return process.env.PHP_BIN;
  }

  if (spawnSync('php', ['-v'], { stdio: 'ignore' }).status === 0) {
    return 'php';
  }

  const laragon = 'C:\\laragon\\bin\\php';

  if (fs.existsSync(laragon)) {
    const versions = fs.readdirSync(laragon)
      .map((name) => path.join(laragon, name, 'php.exe'))
      .filter((file) => fs.existsSync(file))
      .sort()
      .reverse();

    if (versions.length) {
      return versions[0];
    }
  }

  return '';
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
    problems.push('PHP не найден (PHP_BIN или C:\\laragon\\bin\\php) — версию из DownloadService не проверить');
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
}

if (problems.length) {
  problems.forEach((problem) => console.error('FAIL: ' + problem));
  process.exit(1);
}

console.log('plugin ' + newest(pluginVersions) + ', theme ' + newest(themeVersions) + ', composer.json и screenshot на месте');
