/**
 * QA Platform – Test Engine Server
 * Node.js + WebSocket + Playwright
 *
 * How it works:
 *   1. PHP dashboard POSTs a job to POST /run  { url, suite, runId, apiKey }
 *   2. Engine spawns a Playwright worker for that job
 *   3. Worker opens a REAL Chrome/Chromium, navigates to client's URL
 *   4. Every 200 ms it captures a screenshot (JPEG ~80 quality) → base64
 *   5. Screenshot is broadcast over WebSocket to all viewers subscribed to that runId
 *   6. Each step (click, fill, assert) emits a step event with timing + pass/fail
 *   7. On finish, engine POSTs results back to PHP API  /api/test-results
 */

'use strict';

const http       = require('http');
const WebSocket  = require('ws');
const { chromium } = require('playwright');
const crypto     = require('crypto');
const url        = require('url');

// ── Config (override via environment) ──────────────────────────────────────
const PORT         = process.env.ENGINE_PORT    || 3001;
const PHP_API_URL  = process.env.PHP_API_URL    || 'http://localhost:80/api';
const API_SECRET   = process.env.ENGINE_SECRET  || 'change_this_secret';
const FRAME_DELAY  = parseInt(process.env.FRAME_DELAY  || '200');   // ms between screenshots
const MAX_WORKERS  = parseInt(process.env.MAX_WORKERS  || '3');     // concurrent browser instances
const VIEWPORT     = { width: 1280, height: 800 };

// ── State ──────────────────────────────────────────────────────────────────
const jobs    = new Map();   // runId → { status, ws_clients[] }
let   workers = 0;

// ── HTTP server (REST endpoint for PHP to submit jobs) ──────────────────────
const server = http.createServer((req, res) => {
    const parsed = url.parse(req.url, true);

    // Health check
    if (req.method === 'GET' && parsed.pathname === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'ok', workers, maxWorkers: MAX_WORKERS }));
        return;
    }

    // Start a test run
    if (req.method === 'POST' && parsed.pathname === '/run') {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => {
            try {
                const payload = JSON.parse(body);

                // Validate secret
                if (payload.secret !== API_SECRET) {
                    res.writeHead(403, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ error: 'Forbidden' }));
                    return;
                }

                if (workers >= MAX_WORKERS) {
                    res.writeHead(503, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ error: 'All workers busy, try later' }));
                    return;
                }

                const runId = payload.runId || crypto.randomUUID();
                jobs.set(runId, { status: 'queued', clients: [] });

                // Start async — don't await here
                runTest(runId, payload).catch(err => {
                    console.error(`[${runId}] Fatal error:`, err.message);
                    broadcastToRun(runId, { type: 'error', message: err.message });
                    jobs.delete(runId);
                });

                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ runId, wsUrl: `ws://localhost:${PORT}/stream/${runId}` }));
            } catch (e) {
                res.writeHead(400, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ error: 'Bad JSON' }));
            }
        });
        return;
    }

    res.writeHead(404); res.end('Not Found');
});

// ── WebSocket server ───────────────────────────────────────────────────────
const wss = new WebSocket.Server({ server });

wss.on('connection', (ws, req) => {
    const parts  = req.url.split('/');           // /stream/<runId>
    const runId  = parts[parts.length - 1];

    if (!runId || runId === 'stream') {
        ws.close(4000, 'No runId'); return;
    }

    // Register this viewer
    if (!jobs.has(runId)) {
        jobs.set(runId, { status: 'pending', clients: [] });
    }
    jobs.get(runId).clients.push(ws);
    console.log(`[${runId}] Viewer connected (total: ${jobs.get(runId).clients.length})`);

    ws.on('close', () => {
        const job = jobs.get(runId);
        if (job) {
            job.clients = job.clients.filter(c => c !== ws);
        }
    });
});

// ── Broadcast to all viewers of a run ─────────────────────────────────────
function broadcastToRun(runId, data) {
    const job = jobs.get(runId);
    if (!job) return;
    const msg = JSON.stringify(data);
    job.clients.forEach(ws => {
        if (ws.readyState === WebSocket.OPEN) ws.send(msg);
    });
}

// ── Core: Run a test suite with real Playwright ────────────────────────────
async function runTest(runId, payload) {
    workers++;
    const job = jobs.get(runId);
    job.status = 'running';

    const { targetUrl, suite, steps = [] } = payload;
    const results = [];
    let browser, page, screenshotInterval;

    console.log(`[${runId}] Starting — URL: ${targetUrl}, Suite: ${suite}`);
    broadcastToRun(runId, { type: 'status', status: 'starting', targetUrl, suite });

    try {
        // ── Launch real Chromium ───────────────────────────────────────
        browser = await chromium.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--window-size=1280,800',
            ]
        });

        const context = await browser.newContext({
            viewport: VIEWPORT,
            userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/128 Safari/537.36 QABot/1.0',
            ignoreHTTPSErrors: true,
        });

        page = await context.newPage();

        // ── Screenshot streaming loop ──────────────────────────────────
        // Every FRAME_DELAY ms: capture JPEG and broadcast to all viewers
        screenshotInterval = setInterval(async () => {
            try {
                const buf = await page.screenshot({ type: 'jpeg', quality: 80 });
                broadcastToRun(runId, {
                    type:  'frame',
                    runId,
                    frame: buf.toString('base64'),
                    ts:    Date.now(),
                });
            } catch (_) { /* page may be navigating */ }
        }, FRAME_DELAY);

        // ── Navigate to client's real website ─────────────────────────
        broadcastToRun(runId, { type: 'step', index: -1, text: `Navigating to ${targetUrl}`, status: 'running' });

        await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.waitForTimeout(800);

        const title = await page.title();
        broadcastToRun(runId, {
            type: 'step', index: -1,
            text: `Page loaded: "${title}"`,
            status: 'pass', duration: '—'
        });

        // ── Execute each step from the suite ──────────────────────────
        for (let i = 0; i < steps.length; i++) {
            const step = steps[i];
            const start = Date.now();

            broadcastToRun(runId, { type: 'step', index: i, text: step.description, status: 'running' });

            try {
                await executeStep(page, step);
                const dur = ((Date.now() - start) / 1000).toFixed(2) + 's';
                broadcastToRun(runId, { type: 'step', index: i, text: step.description, status: 'pass', duration: dur });
                results.push({ step: step.description, status: 'pass', duration: dur });
            } catch (err) {
                const dur = ((Date.now() - start) / 1000).toFixed(2) + 's';
                broadcastToRun(runId, {
                    type: 'step', index: i,
                    text: step.description,
                    status: 'fail',
                    error: err.message,
                    duration: dur
                });
                results.push({ step: step.description, status: 'fail', error: err.message, duration: dur });

                if (step.stopOnFail) break;
            }
        }

        // ── Final screenshot ───────────────────────────────────────────
        const finalBuf = await page.screenshot({ type: 'jpeg', quality: 85, fullPage: true });
        broadcastToRun(runId, {
            type: 'screenshot',
            label: 'final',
            frame: finalBuf.toString('base64'),
        });

    } catch (err) {
        console.error(`[${runId}] Error:`, err.message);
        broadcastToRun(runId, { type: 'error', message: err.message });
        results.push({ step: 'Engine error', status: 'fail', error: err.message, duration: '0s' });
    } finally {
        if (screenshotInterval) clearInterval(screenshotInterval);
        if (browser) await browser.close();

        workers--;
        job.status = 'done';

        const passed = results.filter(r => r.status === 'pass').length;
        const failed = results.filter(r => r.status === 'fail').length;
        const summary = { runId, passed, failed, total: results.length, results };

        broadcastToRun(runId, { type: 'done', ...summary });
        console.log(`[${runId}] Done — ${passed}/${results.length} passed`);

        // POST results back to PHP
        await postResultsToPhp(runId, summary);

        // Cleanup after 5 min
        setTimeout(() => jobs.delete(runId), 5 * 60 * 1000);
    }
}

// ── Execute a single test step ─────────────────────────────────────────────
async function executeStep(page, step) {
    const { action, selector, value, url: navUrl, assertion, timeout = 10000 } = step;

    switch (action) {

        case 'navigate':
            await page.goto(navUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
            await page.waitForTimeout(500);
            break;

        case 'click':
            await page.waitForSelector(selector, { timeout });
            // Highlight element before clicking (sends a frame with glow effect)
            await page.evaluate((sel) => {
                const el = document.querySelector(sel);
                if (el) {
                    el.style.outline = '3px solid #7c6fff';
                    el.style.outlineOffset = '2px';
                    setTimeout(() => { el.style.outline=''; el.style.outlineOffset=''; }, 600);
                }
            }, selector);
            await page.waitForTimeout(300);
            await page.click(selector);
            await page.waitForTimeout(400);
            break;

        case 'fill':
            await page.waitForSelector(selector, { timeout });
            await page.focus(selector);
            await page.fill(selector, value);
            await page.waitForTimeout(200);
            break;

        case 'type':
            await page.waitForSelector(selector, { timeout });
            await page.focus(selector);
            await page.type(selector, value, { delay: 60 });
            await page.waitForTimeout(200);
            break;

        case 'select':
            await page.waitForSelector(selector, { timeout });
            await page.selectOption(selector, value);
            break;

        case 'check':
            await page.waitForSelector(selector, { timeout });
            await page.check(selector);
            break;

        case 'hover':
            await page.waitForSelector(selector, { timeout });
            await page.hover(selector);
            await page.waitForTimeout(300);
            break;

        case 'wait':
            await page.waitForSelector(selector, { timeout });
            break;

        case 'wait_url':
            await page.waitForURL(value, { timeout });
            break;

        case 'wait_ms':
            await page.waitForTimeout(parseInt(value));
            break;

        case 'scroll':
            await page.evaluate((sel) => {
                const el = sel ? document.querySelector(sel) : window;
                if (el) el.scrollIntoView ? el.scrollIntoView({ behavior: 'smooth' }) : el.scrollBy(0, 400);
            }, selector);
            await page.waitForTimeout(400);
            break;

        case 'assert_text':
            await page.waitForSelector(selector, { timeout });
            const text = await page.textContent(selector);
            if (!text.includes(value)) throw new Error(`Expected "${value}" in "${text}"`);
            break;

        case 'assert_visible':
            await page.waitForSelector(selector, { state: 'visible', timeout });
            break;

        case 'assert_url':
            const currentUrl = page.url();
            if (!currentUrl.includes(value)) throw new Error(`URL ${currentUrl} does not contain "${value}"`);
            break;

        case 'assert_title':
            const title = await page.title();
            if (!title.includes(value)) throw new Error(`Title "${title}" does not contain "${value}"`);
            break;

        case 'screenshot':
            // Screenshot steps are just captured in the stream already
            break;

        default:
            throw new Error(`Unknown action: ${action}`);
    }
}

// ── POST final results to PHP ──────────────────────────────────────────────
async function postResultsToPhp(runId, summary) {
    try {
        const body = JSON.stringify({ ...summary, secret: API_SECRET });
        const options = {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) },
        };
        const phpUrl = new URL(`${PHP_API_URL}/test-results`);
        const mod = phpUrl.protocol === 'https:' ? require('https') : require('http');

        await new Promise((resolve, reject) => {
            const req = mod.request(phpUrl, options, res => {
                res.on('data', () => {}); res.on('end', resolve);
            });
            req.on('error', reject);
            req.write(body); req.end();
        });
        console.log(`[${runId}] Results posted to PHP`);
    } catch (err) {
        console.error(`[${runId}] Failed to post results:`, err.message);
    }
}

// ── Start ──────────────────────────────────────────────────────────────────
server.listen(PORT, () => {
    console.log(`QA Engine running on port ${PORT}`);
    console.log(`  POST /run        → submit a test job`);
    console.log(`  GET  /health     → engine status`);
    console.log(`  WS   /stream/:id → live screenshot stream`);
});
