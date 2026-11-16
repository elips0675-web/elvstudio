import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '..');
const STATIC = path.join(ROOT, 'demo-static');
const PORT = 18080 + Math.floor(Math.random() * 500);
const DEBUG_PORT = 19080 + Math.floor(Math.random() * 500);

function commandWorks(command, args) {
  const result = spawnSync(command, args, { stdio: 'ignore' });

  return result.status === 0;
}

function resolvePhp() {
  if (process.env.PHP_BIN) {
    return process.env.PHP_BIN;
  }

  if (commandWorks('php', ['-v'])) {
    return 'php';
  }

  if (process.platform === 'win32') {
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
  }

  throw new Error('PHP not found. Set PHP_BIN to the php executable.');
}

function firstExisting(paths) {
  return paths.find((file) => file && fs.existsSync(file)) || '';
}

function resolveChrome() {
  const candidates = process.platform === 'win32'
    ? [
        process.env.CHROME_BIN,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
      ]
    : [
        process.env.CHROME_BIN,
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
      ];

  const chrome = firstExisting(candidates);

  if (!chrome) {
    throw new Error('Chrome/Chromium not found. Set CHROME_BIN.');
  }

  return chrome;
}

function collectPages(dir) {
  const files = [];

  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);

    if (entry.isDirectory()) {
      files.push(...collectPages(full));
    } else if (entry.name.endsWith('.html')) {
      files.push(full);
    }
  }

  return files;
}

function pageUrl(file) {
  const relative = path.relative(STATIC, file).split(path.sep).join('/');

  if (relative === 'index.html') {
    return '/';
  }

  return '/' + relative.replace(/\/index\.html$/, '/');
}

async function waitFor(url, label) {
  for (let attempt = 0; attempt < 60; attempt += 1) {
    try {
      const response = await fetch(url);

      if (response.ok) {
        return;
      }
    } catch {}

    await new Promise((resolve) => setTimeout(resolve, 250));
  }

  throw new Error(`${label} did not start: ${url}`);
}

async function stopProcess(child) {
  if (!child || child.exitCode !== null) {
    return;
  }

  if (process.platform === 'win32') {
    spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore' });
    await new Promise((resolve) => {
      const timer = setTimeout(resolve, 3000);
      child.once('exit', () => {
        clearTimeout(timer);
        resolve();
      });
    });
    return;
  }

  child.kill('SIGTERM');
  await new Promise((resolve) => {
    const timer = setTimeout(resolve, 3000);
    child.once('exit', () => {
      clearTimeout(timer);
      resolve();
    });
  });
}

const php = resolvePhp();
const chromePath = resolveChrome();
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'elv-console-'));
const pages = collectPages(STATIC).sort();
const server = spawn(php, ['-S', `127.0.0.1:${PORT}`, '-t', STATIC], { cwd: STATIC, stdio: 'ignore' });
const chrome = spawn(chromePath, [
  '--headless=new',
  '--disable-gpu',
  '--no-sandbox',
  '--no-first-run',
  '--no-default-browser-check',
  '--disable-background-networking',
  `--remote-debugging-port=${DEBUG_PORT}`,
  `--user-data-dir=${profile}`,
  'about:blank',
], { stdio: 'ignore' });

const failures = [];

try {
  await Promise.all([
    waitFor(`http://127.0.0.1:${DEBUG_PORT}/json/version`, 'Chrome'),
    waitFor(`http://127.0.0.1:${PORT}/`, 'Static server'),
  ]);

  const targetResponse = await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/new?about:blank`, { method: 'PUT' });

  if (!targetResponse.ok) {
    throw new Error(`Chrome target: ${targetResponse.status}`);
  }

  const target = await targetResponse.json();
  const socket = new WebSocket(target.webSocketDebuggerUrl);

  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });

  let nextId = 0;
  const pending = new Map();
  const loadWaiters = [];
  let pageEvents = [];

  socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);

    if (message.id && pending.has(message.id)) {
      const { resolve, reject } = pending.get(message.id);
      pending.delete(message.id);

      if (message.error) {
        reject(new Error(JSON.stringify(message.error)));
      } else {
        resolve(message.result);
      }

      return;
    }

    if (message.method === 'Page.loadEventFired') {
      for (const resolve of loadWaiters.splice(0)) {
        resolve();
      }

      return;
    }

    if (message.method === 'Runtime.consoleAPICalled' && ['error', 'assert'].includes(message.params.type)) {
      pageEvents.push({
        kind: 'console',
        text: message.params.args.map((arg) => arg.value ?? arg.description ?? arg.type).join(' '),
      });
    }

    if (message.method === 'Runtime.exceptionThrown') {
      const details = message.params.exceptionDetails;

      pageEvents.push({
        kind: 'exception',
        text: details.exception?.description || details.text || 'exception',
      });
    }

    if (message.method === 'Log.entryAdded' && message.params.entry.level === 'error') {
      pageEvents.push({ kind: 'log', text: message.params.entry.text });
    }

    if (message.method === 'Network.responseReceived' && message.params.response.status >= 500) {
      pageEvents.push({
        kind: 'http',
        text: `${message.params.response.status} ${message.params.response.url}`,
      });
    }
  });

  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++nextId;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
  });

  await send('Page.enable');
  await send('Runtime.enable');
  await send('Log.enable');
  await send('Network.enable');
  await send('Emulation.setDeviceMetricsOverride', {
    width: 360,
    height: 800,
    deviceScaleFactor: 1,
    mobile: true,
  });
  await send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });

  for (const file of pages) {
    const url = pageUrl(file);
    pageEvents = [];
    const loaded = new Promise((resolve) => loadWaiters.push(resolve));
    await send('Page.navigate', { url: `http://127.0.0.1:${PORT}${url}` });
    await Promise.race([loaded, new Promise((resolve) => setTimeout(resolve, 15000))]);
    await new Promise((resolve) => setTimeout(resolve, 300));

    const layoutResult = await send('Runtime.evaluate', {
      expression: `(() => {
        const vw = window.innerWidth;
        const root = document.documentElement;
        const body = document.body;
        return {
          innerWidth: vw,
          scrollWidth: Math.max(root.scrollWidth, body ? body.scrollWidth : 0),
          overflow: Math.max(root.scrollWidth, body ? body.scrollWidth : 0) > vw + 1,
        };
      })()`,
      returnByValue: true,
    });

    const layout = layoutResult.result.value;

    if (layout.overflow) {
      pageEvents.push({
        kind: 'layout',
        text: `horizontal overflow: innerWidth ${layout.innerWidth}, scrollWidth ${layout.scrollWidth}`,
      });
    }

    if (pageEvents.length) {
      failures.push({ url, events: [...pageEvents] });
    }
  }

  socket.close();
  await fetch(`http://127.0.0.1:${DEBUG_PORT}/json/close/${target.id}`);
} finally {
  await stopProcess(chrome);
  await stopProcess(server);

  for (let attempt = 0; attempt < 10; attempt++) {
    try {
      fs.rmSync(profile, { recursive: true, force: true });
      break;
    } catch (error) {
      if (attempt === 9) {
        console.warn(`cleanup skipped: ${profile} (${error.code || error.message})`);
        break;
      }

      await new Promise((resolve) => setTimeout(resolve, 250));
    }
  }
}

console.log(`checked ${pages.length} static pages`);

if (failures.length) {
  console.error(`\n${failures.length} page(s) with console errors:`);

  for (const failure of failures) {
    console.error(`  x ${failure.url}`);

    for (const event of failure.events) {
      console.error(`      [${event.kind}] ${event.text}`);
    }
  }

  process.exit(1);
}

console.log('\nOK');
