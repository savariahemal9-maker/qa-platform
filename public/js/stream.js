/**
 * stream.js  –  Live WebSocket client for the QA runner page
 *
 * Receives from engine/server.js:
 *   { type: 'frame',      frame: base64jpeg,  ts }
 *   { type: 'step',       index, text, status, duration?, error? }
 *   { type: 'status',     status, targetUrl, suite }
 *   { type: 'screenshot', label, frame: base64jpeg }
 *   { type: 'done',       passed, failed, total, results[] }
 *   { type: 'error',      message }
 */

class TestStreamClient {
    /**
     * @param {object} opts
     * @param {string}      opts.wsUrl       WebSocket URL   wss://host:3001/stream/<runId>
     * @param {HTMLElement} opts.streamImg   <img> that shows the live browser frames
     * @param {HTMLElement} opts.stepsEl     container where step rows are appended
     * @param {HTMLElement} opts.urlDisplay  element showing current URL
     * @param {HTMLElement} opts.statusBadge badge showing run status
     * @param {HTMLElement} opts.passEl      pass count display
     * @param {HTMLElement} opts.failEl      fail count display
     * @param {HTMLElement} opts.timeEl      elapsed time display
     * @param {HTMLElement} opts.fpsEl       frames-per-second display
     * @param {HTMLElement} opts.shotsEl     screenshot thumbnails row
     * @param {Function}    opts.onDone      callback(summary) when test finishes
     */
    constructor(opts) {
        this.opts      = opts;
        this.ws        = null;
        this.pass      = 0;
        this.fail      = 0;
        this.frames    = 0;
        this.startTime = null;
        this.timerInt  = null;
        this.fpsCount  = 0;
        this.fpsInt    = null;
        this.stepEls   = {};     // index → DOM element
    }

    connect() {
        this.ws = new WebSocket(this.opts.wsUrl);
        this._setStatus('connecting', 'Connecting…');

        this.ws.addEventListener('open', () => {
            console.log('[stream] WS connected');
            this._setStatus('connected', 'Connected');
        });

        this.ws.addEventListener('message', e => {
            try {
                const msg = JSON.parse(e.data);
                this._handle(msg);
            } catch (_) {}
        });

        this.ws.addEventListener('close', () => {
            console.log('[stream] WS closed');
            this._setStatus('idle', 'Disconnected');
            this._stopTimers();
        });

        this.ws.addEventListener('error', () => {
            this._setStatus('error', 'Connection error');
        });
    }

    disconnect() {
        this._stopTimers();
        if (this.ws) { this.ws.close(); this.ws = null; }
    }

    // ── Message handler ──────────────────────────────────────────────────
    _handle(msg) {
        switch (msg.type) {

            case 'status':
                this._setStatus('running', 'Testing…');
                if (this.opts.urlDisplay) this.opts.urlDisplay.textContent = msg.targetUrl || '';
                this._startTimers();
                break;

            case 'frame':
                this.frames++;
                this.fpsCount++;
                if (this.opts.streamImg) {
                    this.opts.streamImg.src = 'data:image/jpeg;base64,' + msg.frame;
                }
                break;

            case 'step':
                this._renderStep(msg);
                break;

            case 'screenshot':
                this._addThumbnail(msg.label, msg.frame);
                break;

            case 'done':
                this._onDone(msg);
                break;

            case 'error':
                this._setStatus('error', 'Error: ' + msg.message);
                this._stopTimers();
                break;
        }
    }

    // ── Step rendering ───────────────────────────────────────────────────
    _renderStep(msg) {
        const { index, text, status, duration, error } = msg;
        const container = this.opts.stepsEl;
        if (!container) return;

        let el = this.stepEls[index];

        if (!el) {
            el = document.createElement('div');
            el.className = 'step-row';
            el.innerHTML = `
                <div class="step-ic" id="sic-${index}">○</div>
                <div class="step-body">
                    <div class="step-txt" id="stxt-${index}">${this._esc(text)}</div>
                    ${error ? `<div class="step-err">${this._esc(error)}</div>` : ''}
                </div>
                <div class="step-dur" id="sdur-${index}"></div>`;
            container.appendChild(el);
            this.stepEls[index] = el;
        }

        // Remove previous state classes
        el.classList.remove('active', 'pass', 'fail');

        const ic  = el.querySelector(`#sic-${index}`);
        const dur = el.querySelector(`#sdur-${index}`);

        if (status === 'running') {
            el.classList.add('active');
            if (ic) ic.textContent = '⟳';
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        } else if (status === 'pass') {
            el.classList.add('pass');
            if (ic)  ic.textContent  = '✓';
            if (dur) dur.textContent = duration || '';
            this.pass++;
            if (this.opts.passEl) this.opts.passEl.textContent = this.pass;

        } else if (status === 'fail') {
            el.classList.add('fail');
            if (ic)  ic.textContent  = '✗';
            if (dur) dur.textContent = duration || '';
            if (error) {
                const errEl = document.createElement('div');
                errEl.className = 'step-err';
                errEl.textContent = error;
                el.querySelector('.step-body').appendChild(errEl);
            }
            this.fail++;
            if (this.opts.failEl) this.opts.failEl.textContent = this.fail;
        }
    }

    // ── Thumbnail strip ──────────────────────────────────────────────────
    _addThumbnail(label, base64) {
        const row = this.opts.shotsEl;
        if (!row) return;

        // Clear placeholder text on first shot
        if (row.querySelector('.placeholder')) row.innerHTML = '';

        const wrap = document.createElement('div');
        wrap.className = 'sshot';
        const img = document.createElement('img');
        img.src    = 'data:image/jpeg;base64,' + base64;
        img.style  = 'width:100%;height:100%;object-fit:cover;border-radius:4px;';
        const lbl  = document.createElement('div');
        lbl.className = 'sshot-lbl';
        lbl.textContent = label;
        wrap.appendChild(img);
        wrap.appendChild(lbl);
        row.appendChild(wrap);

        // Animate in
        requestAnimationFrame(() => wrap.classList.add('in'));
    }

    // ── Done ─────────────────────────────────────────────────────────────
    _onDone(msg) {
        this._stopTimers();
        this._setStatus('done', `Done — ${msg.passed}/${msg.total} passed`);
        if (this.opts.onDone) this.opts.onDone(msg);
    }

    // ── Timers ───────────────────────────────────────────────────────────
    _startTimers() {
        this.startTime = Date.now();
        this.timerInt  = setInterval(() => {
            const sec = ((Date.now() - this.startTime) / 1000).toFixed(1);
            if (this.opts.timeEl) this.opts.timeEl.textContent = sec + 's';
        }, 100);

        this.fpsInt = setInterval(() => {
            if (this.opts.fpsEl) this.opts.fpsEl.textContent = this.fpsCount;
            this.fpsCount = 0;
        }, 1000);
    }

    _stopTimers() {
        clearInterval(this.timerInt);
        clearInterval(this.fpsInt);
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    _setStatus(cls, text) {
        const badge = this.opts.statusBadge;
        if (!badge) return;
        badge.dataset.status = cls;
        badge.textContent    = text;
    }

    _esc(str) {
        if (!str) return '';
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
}

// Export for both module and global script use
if (typeof module !== 'undefined') module.exports = TestStreamClient;
else window.TestStreamClient = TestStreamClient;
