import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const base = (process.env.CALCULATOR_BASE_URL || 'http://127.0.0.1:8090').replace(/\/$/, '');
const chromeCandidates = [
    process.env.ELV_CHROME_BIN || '',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    '/usr/bin/google-chrome',
    '/usr/bin/google-chrome-stable',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
];

function findChrome() {
    for (const candidate of chromeCandidates) {
        if (candidate && existsSync(candidate)) {
            return candidate;
        }
    }

    throw new Error('Chrome/Chromium not found. Set ELV_CHROME_BIN.');
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitFor(fn, timeout = 15000, label = 'condition') {
    const started = Date.now();

    while (Date.now() - started < timeout) {
        try {
            if (await fn()) {
                return;
            }
        } catch (error) {
            // keep polling until the timeout
        }

        await sleep(150);
    }

    throw new Error('Timed out waiting for ' + label);
}

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

class Cdp {
    constructor(url) {
        this.url = url;
        this.id = 0;
        this.pending = new Map();
    }

    async open() {
        this.ws = new WebSocket(this.url);

        await new Promise((resolve, reject) => {
            this.ws.addEventListener('open', resolve, { once: true });
            this.ws.addEventListener('error', reject, { once: true });
        });

        this.ws.addEventListener('message', (event) => {
            const message = JSON.parse(event.data);

            if (!message.id || !this.pending.has(message.id)) {
                return;
            }

            const entry = this.pending.get(message.id);
            this.pending.delete(message.id);

            if (message.error) {
                entry.reject(new Error(message.error.message || 'CDP error'));
                return;
            }

            entry.resolve(message.result);
        });
    }

    send(method, params = {}) {
        return new Promise((resolve, reject) => {
            const id = ++this.id;
            const timer = setTimeout(() => {
                this.pending.delete(id);
                reject(new Error('CDP timeout: ' + method));
            }, 30000);

            this.pending.set(id, {
                resolve: (value) => {
                    clearTimeout(timer);
                    resolve(value);
                },
                reject: (error) => {
                    clearTimeout(timer);
                    reject(error);
                },
            });

            this.ws.send(JSON.stringify({ id, method, params }));
        });
    }

    async evaluate(expression) {
        const result = await this.send('Runtime.evaluate', {
            expression,
            returnByValue: true,
            awaitPromise: true,
        });

        if (result.exceptionDetails) {
            throw new Error(result.exceptionDetails.text || 'Evaluation failed');
        }

        return result.result.value;
    }

    close() {
        try {
            this.ws.close();
        } catch (error) {
            // already closed
        }
    }
}

async function openPage(port, url) {
    const response = await fetch(
        'http://127.0.0.1:' + port + '/json/new?' + encodeURIComponent(url),
        { method: 'PUT' }
    );

    if (!response.ok) {
        throw new Error('Cannot create Chrome target: ' + response.status);
    }

    const target = await response.json();
    const cdp = new Cdp(target.webSocketDebuggerUrl);
    await cdp.open();
    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');

    return cdp;
}

async function waitForCalculator(cdp, expectedPath = '') {
    await waitFor(async () => {
        const state = await cdp.evaluate(`(() => ({
            ready: document.readyState,
            href: window.location.href,
            root: !!document.querySelector('[data-calculator]'),
            total: (document.querySelector('[data-total-launch]') || {}).textContent || ''
        }))()`);

        return state.ready === 'complete'
            && state.root
            && state.total.trim() !== ''
            && (!expectedPath || state.href.includes(expectedPath));
    }, 20000, 'calculator');
}

async function main() {
    const response = await fetch(base + '/calculator/');

    if (!response.ok) {
        throw new Error('Calculator is not reachable: ' + base + '/calculator/');
    }

    const chrome = findChrome();
    const port = 9300 + Math.floor(Math.random() * 500);
    const profile = mkdtempSync(join(tmpdir(), 'elv-calc-e2e-'));
    const child = spawn(chrome, [
        '--headless=new',
        '--disable-gpu',
        '--disable-dev-shm-usage',
        '--disable-extensions',
        '--no-first-run',
        '--no-sandbox',
        '--remote-debugging-port=' + port,
        '--user-data-dir=' + profile,
        'about:blank',
    ], {
        stdio: ['ignore', 'ignore', 'pipe'],
        windowsHide: true,
    });

    let stderr = '';
    child.stderr.on('data', (chunk) => {
        stderr += chunk.toString();
    });

    let cdp = null;

    try {
        await waitFor(async () => {
            const probe = await fetch('http://127.0.0.1:' + port + '/json/version');
            return probe.ok;
        }, 20000, 'Chrome debugging port');

        cdp = await openPage(port, base + '/calculator/');
        await waitForCalculator(cdp);

        const initial = await cdp.evaluate(`(() => ({
            plan: document.querySelector('input[name="plan"]:checked').value,
            launch: document.querySelector('[data-total-launch]').textContent.trim()
        }))()`);

        assert(initial.plan === 'single', 'Expected single plan by default, got ' + initial.plan);
        assert(initial.launch === '$79', 'Expected $79 by default, got ' + initial.launch);

        const recommended = await cdp.evaluate(`(() => {
            const q = (selector) => document.querySelector(selector);
            q('input[name="task"][value="services"]').click();
            q('[data-calc-apply-recommend]').click();

            return {
                plan: q('input[name="plan"]:checked').value,
                modules: Array.from(document.querySelectorAll('input[name="modules"]:checked')).map((item) => item.value).sort(),
                launch: q('[data-total-launch]').textContent.trim(),
                text: q('[data-recommend-text]').textContent.trim()
            };
        })()`);

        assert(recommended.plan === 'single', 'Recommended plan should be single');
        assert(
            JSON.stringify(recommended.modules) === JSON.stringify(['booking', 'feedback']),
            'Recommended modules differ: ' + recommended.modules.join(',')
        );
        assert(recommended.launch === '$157', 'Recommended total should be $157, got ' + recommended.launch);
        assert(recommended.text.includes('Запись'), 'Recommendation text is empty or wrong');

        const selected = await cdp.evaluate(`(() => {
            const q = (selector) => document.querySelector(selector);
            q('input[name="plan"][value="extended"]').click();
            document.querySelectorAll('input[name="modules"]').forEach((input) => {
                input.checked = input.value === 'booking' || input.value === 'shop';
            });
            q('input[name="modules"][value="shop"]').dispatchEvent(new Event('change', { bubbles: true }));
            q('[data-calc-show-result]').click();

            return {
                hidden: document.getElementById('calculator-result').hidden,
                launch: q('[data-total-launch]').textContent.trim(),
                year: q('[data-total-year]').textContent.trim(),
                pdf: q('[data-calc-pdf]').getAttribute('href')
            };
        })()`);

        assert(selected.hidden === false, 'Result block stayed hidden');
        assert(selected.launch === '$587', 'Expected $587 for extended + booking + shop, got ' + selected.launch);
        assert(selected.year === '$587', 'First-year total should match launch, got ' + selected.year);
        assert(selected.pdf.includes('plan=extended'), 'PDF link has no plan');
        assert(selected.pdf.includes('modules=booking%2Cshop') || selected.pdf.includes('modules=booking,shop'), 'PDF link has no modules');

        await cdp.send('Page.navigate', {
            url: base + '/calculator/?plan=extended&modules=booking,shop&task=shop&hosting=need',
        });
        await waitForCalculator(cdp, '/calculator/?plan=extended');

        const fromUrl = await cdp.evaluate(`(() => {
            const q = (selector) => document.querySelector(selector);
            return {
                plan: q('input[name="plan"]:checked').value,
                modules: Array.from(document.querySelectorAll('input[name="modules"]:checked')).map((item) => item.value).sort(),
                launch: q('[data-total-launch]').textContent.trim(),
                pdf: q('[data-calc-pdf]').getAttribute('href')
            };
        })()`);

        assert(fromUrl.plan === 'extended', 'URL state did not restore plan');
        assert(JSON.stringify(fromUrl.modules) === JSON.stringify(['booking', 'shop']), 'URL state did not restore modules');
        assert(fromUrl.launch === '$587', 'URL state total should be $587, got ' + fromUrl.launch);
        assert(fromUrl.pdf.includes('task=shop'), 'PDF link lost task after navigation');
        assert(fromUrl.pdf.includes('hosting=need'), 'PDF link lost hosting after navigation');

        const form = await cdp.evaluate(`(() => {
            const q = (selector) => document.querySelector(selector);
            q('#calc-name').value = 'E2E';
            q('#calc-email').value = 'e2e@example.test';
            q('input[name="consent"]').checked = true;

            return q('#calculator-request-form').checkValidity();
        })()`);

        assert(form === true, 'Calculator request form is not valid with filled fields');

        const pdfResponse = await fetch(
            base + '/calculator/quote.pdf?plan=extended&modules=booking,shop&task=shop&hosting=need'
        );
        const pdf = Buffer.from(await pdfResponse.arrayBuffer());
        const disposition = pdfResponse.headers.get('content-disposition') || '';

        assert(pdfResponse.ok, 'PDF route returned ' + pdfResponse.status);
        assert((pdfResponse.headers.get('content-type') || '').includes('application/pdf'), 'PDF content type');
        assert(pdf.toString('ascii', 0, 4) === '%PDF', 'Response is not a PDF');
        assert(pdf.length > 20000, 'PDF is too small: ' + pdf.length);
        assert(disposition.includes('elv-quote-ELV-'), 'PDF filename has no quote number');

        console.log('OK: calculator E2E default=$79 recommend=$157 result=$587 pdf=' + pdf.length + ' bytes');
    } finally {
        if (cdp) {
            cdp.close();
        }

        child.kill();

        await sleep(200);

        try {
            rmSync(profile, { recursive: true, force: true });
        } catch (error) {
            // Chrome may still hold a file for a moment
        }

        if (stderr && /Error/.test(stderr) && !process.exitCode) {
            console.error(stderr.split('\n').find((line) => /Error/.test(line)) || '');
        }
    }
}

main().catch((error) => {
    console.error('FAIL: ' + error.message);
    process.exit(1);
});
