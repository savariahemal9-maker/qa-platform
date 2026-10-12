<?php
/**
 * run.php  –  Live Test Runner Page
 *
 * URL:  /run.php?run_id=<uuid>
 *
 * This page:
 *  1. Looks up the test run in MySQL
 *  2. Renders the UI with stream.js connected to the engine WebSocket
 *  3. The browser connects directly to wss://engine-host:3001/stream/<runId>
 *  4. Live JPEG frames arrive via WebSocket and are shown in an <img> tag
 *  5. When done, results are saved by the engine back to PHP via /api/test-results.php
 */

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/functions.php';
requireLogin();

$runId  = sanitize($_GET['run_id'] ?? '');
$user   = currentUser();

if (!$runId) redirect('/dashboard.php');

// Fetch run details
$db   = getDB();
$stmt = $db->prepare("
    SELECT tr.*, p.name AS project_name, p.url AS project_url,
           ts.name AS suite_name
    FROM test_runs tr
    JOIN projects p  ON p.id  = tr.project_id
    JOIN test_suites ts ON ts.id = tr.suite_id
    WHERE tr.id = ? AND tr.company_id = ?
    LIMIT 1
");
$stmt->execute([$runId, $user['company_id']]);
$run = $stmt->fetch();

if (!$run) {
    redirect('/dashboard.php');
}

// Engine WebSocket URL
$engineHost = defined('ENGINE_HOST') ? ENGINE_HOST : 'localhost';
$enginePort = defined('ENGINE_PORT') ? ENGINE_PORT : 3001;
$wsUrl      = "ws://{$engineHost}:{$enginePort}/stream/{$runId}";

// For production with HTTPS, use wss://
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $wsUrl = "wss://{$engineHost}:{$enginePort}/stream/{$runId}";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Live Run – <?= sanitize($run['suite_name']) ?> – QA Platform</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --bg:     #07090f;
    --surf:   #0d1117;
    --panel:  #111827;
    --card:   #161e2e;
    --border: #1c2a3e;
    --bord2:  #253350;
    --text:   #e4ecf7;
    --muted:  #4a6580;
    --acc:    #7c6fff;
    --pass:   #22d3a0;
    --fail:   #f43f5e;
    --warn:   #fbbf24;
    --acclo:  rgba(124,111,255,.12);
    --passbg: rgba(34,211,160,.08);
    --failbg: rgba(244,63,94,.06);
    color-scheme: dark;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);padding:14px;min-height:100vh}

/* top bar */
.topbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px}
.logo{display:flex;align-items:center;gap:9px}
.logo-box{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--acc),#6366f1);display:flex;align-items:center;justify-content:center;font-size:16px}
.logo-name{font-size:14px;font-weight:700}
.breadcrumb{font-size:11px;color:var(--muted);font-family:'JetBrains Mono',monospace}
.breadcrumb a{color:var(--muted);text-decoration:none}
.breadcrumb a:hover{color:var(--text)}

/* status badge */
[data-status]{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:6px;font-size:11px;font-family:'JetBrains Mono',monospace;border:1px solid var(--border);color:var(--muted)}
[data-status="connecting"]{color:var(--warn);border-color:var(--warn);background:rgba(251,191,36,.07)}
[data-status="running"],[data-status="connected"]{color:var(--acc);border-color:var(--acc);background:var(--acclo);animation:sblink 2s infinite}
[data-status="done"]{color:var(--pass);border-color:var(--pass);background:var(--passbg)}
[data-status="error"]{color:var(--fail);border-color:var(--fail);background:var(--failbg)}
@keyframes sblink{0%,100%{opacity:1}50%{opacity:.5}}

/* layout */
.main{display:grid;grid-template-columns:1fr 320px;gap:12px}
@media(max-width:720px){.main{grid-template-columns:1fr}}

/* browser window */
.browser-wrap{background:var(--panel);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.browser-chrome{background:#090e18;border-bottom:1px solid var(--border);padding:8px 12px;display:flex;align-items:center;gap:9px}
.tlights{display:flex;gap:4px}
.tl{width:10px;height:10px;border-radius:50%}
.tl-r{background:#ff5f56}.tl-y{background:#ffbd2e}.tl-g{background:#27c93f}
.addr-bar{flex:1;background:var(--surf);border:1px solid var(--bord2);border-radius:5px;padding:5px 10px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);display:flex;align-items:center;gap:6px;overflow:hidden}
.ssl{width:6px;height:6px;border-radius:50%;background:var(--pass);flex-shrink:0}
.addr-text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* live frame */
.stream-area{position:relative;background:#111;min-height:380px;display:flex;align-items:center;justify-content:center}
#streamImg{width:100%;height:auto;display:block;object-fit:contain}
.stream-placeholder{text-align:center;color:var(--muted);font-size:13px;padding:40px;position:absolute}
.stream-placeholder .spin{display:inline-block;font-size:28px;animation:spin 1.2s linear infinite;margin-bottom:10px}
@keyframes spin{to{transform:rotate(360deg)}}

/* stream info */
.stream-info{display:flex;flex-wrap:wrap;gap:12px;padding:6px 12px;border-top:1px solid var(--border);background:#090e18}
.si{font-size:10px;font-family:'JetBrains Mono',monospace;color:var(--muted)}
.si strong{color:var(--text)}

/* right side */
.side{display:flex;flex-direction:column;gap:10px}

/* stats */
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.stat{background:var(--panel);border:1px solid var(--border);border-radius:9px;padding:9px 11px;text-align:center}
.stat-v{font-size:22px;font-weight:700;font-family:'JetBrains Mono',monospace;line-height:1}
.stat-l{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
.s-p .stat-v{color:var(--pass)}.s-f .stat-v{color:var(--fail)}.s-t .stat-v{color:var(--warn)}

/* steps */
.steps-card{background:var(--panel);border:1px solid var(--border);border-radius:11px;overflow:hidden;flex:1}
.card-hd{padding:9px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.card-t{font-size:12px;font-weight:600}
.card-s{font-size:10px;color:var(--muted);font-family:'JetBrains Mono',monospace}
.steps-body{max-height:260px;overflow-y:auto;padding:5px}
.steps-body::-webkit-scrollbar{width:3px}
.steps-body::-webkit-scrollbar-thumb{background:var(--bord2);border-radius:3px}

.step-row{display:flex;align-items:flex-start;gap:7px;padding:6px 7px;border-radius:6px;font-size:11px;opacity:.25;transition:opacity .2s,background .2s}
.step-row.active{opacity:1;background:var(--acclo)}
.step-row.pass{opacity:1}
.step-row.fail{opacity:1;background:var(--failbg)}
.step-ic{width:15px;height:15px;border-radius:50%;border:1.5px solid var(--bord2);display:flex;align-items:center;justify-content:center;font-size:8px;color:var(--muted);flex-shrink:0;margin-top:1px}
.step-row.active .step-ic{border-color:var(--acc);color:var(--acc);animation:spin .8s linear infinite}
.step-row.pass .step-ic{border-color:var(--pass);color:var(--pass);background:var(--passbg)}
.step-row.fail .step-ic{border-color:var(--fail);color:var(--fail);background:var(--failbg)}
.step-body{flex:1;min-width:0}
.step-txt{color:var(--muted);font-family:'JetBrains Mono',monospace;line-height:1.4;word-break:break-word}
.step-row.active .step-txt,.step-row.pass .step-txt{color:var(--text)}
.step-row.fail .step-txt{color:var(--text)}
.step-err{font-size:10px;color:var(--fail);margin-top:3px;font-family:'JetBrains Mono',monospace;word-break:break-all}
.step-dur{font-size:10px;color:var(--muted);white-space:nowrap;flex-shrink:0}
.step-row.pass .step-dur{color:var(--pass);opacity:.7}

/* screenshots */
.shots-card{background:var(--panel);border:1px solid var(--border);border-radius:11px;padding:10px 12px}
.shots-t{font-size:12px;font-weight:600;margin-bottom:7px}
.shots-row{display:flex;gap:5px;overflow-x:auto}
.shots-row::-webkit-scrollbar{height:3px}
.shots-row::-webkit-scrollbar-thumb{background:var(--bord2);border-radius:3px}
.placeholder{font-size:11px;color:var(--muted);font-family:'JetBrains Mono',monospace}
.sshot{flex-shrink:0;width:70px;height:46px;border-radius:5px;border:1px solid var(--bord2);overflow:hidden;position:relative;opacity:0;transform:scale(.8);transition:opacity .3s,transform .3s}
.sshot.in{opacity:1;transform:scale(1)}
.sshot-lbl{position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,.75);font-size:7px;font-family:'JetBrains Mono',monospace;color:#fff;text-align:center;padding:1px 2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* actions */
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{display:flex;align-items:center;gap:5px;padding:7px 14px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;border:none;transition:all .15s}
.btn-danger{background:var(--failbg);color:var(--fail);border:1px solid var(--fail)}
.btn-danger:hover{background:var(--fail);color:#fff}
.btn-back{background:var(--card);color:var(--muted);border:1px solid var(--bord2)}
.btn-back:hover{color:var(--text)}
</style>
</head>
<body>

<div class="topbar">
    <div class="logo">
        <div class="logo-box">⚡</div>
        <div>
            <div class="logo-name">QA Platform</div>
            <div class="breadcrumb">
                <a href="/dashboard.php">Dashboard</a> /
                <a href="/project.php?id=<?= urlencode($run['project_id']) ?>"><?= sanitize($run['project_name']) ?></a> /
                Live Run
            </div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span id="statusBadge" data-status="connecting">Connecting…</span>
        <div class="si">FPS: <strong id="fpsEl">—</strong></div>
        <div class="si">Time: <strong id="timeEl">0.0s</strong></div>
    </div>
</div>

<div class="main">

    <!-- LIVE BROWSER STREAM -->
    <div class="browser-wrap">
        <div class="browser-chrome">
            <div class="tlights">
                <div class="tl tl-r"></div>
                <div class="tl tl-y"></div>
                <div class="tl tl-g"></div>
            </div>
            <div class="addr-bar">
                <div class="ssl"></div>
                <div class="addr-text" id="addrText"><?= sanitize($run['project_url']) ?></div>
            </div>
        </div>

        <div class="stream-area">
            <!-- This <img> src is replaced by base64 JPEG every 200ms from WebSocket -->
            <img id="streamImg" src="" alt="Live browser stream" style="display:none">

            <div class="stream-placeholder" id="streamPlaceholder">
                <div class="spin">⚡</div>
                <div>Waiting for Playwright to start…</div>
                <div style="margin-top:6px;font-size:11px;font-family:'JetBrains Mono',monospace;color:var(--muted)">
                    Real browser launching on EC2 → connecting over WebSocket
                </div>
            </div>
        </div>

        <div class="stream-info">
            <div class="si">Suite: <strong><?= sanitize($run['suite_name']) ?></strong></div>
            <div class="si">Target: <strong><?= sanitize($run['project_url']) ?></strong></div>
            <div class="si">Viewport: <strong>1280×800</strong></div>
            <div class="si">Engine: <strong>Playwright + Chromium</strong></div>
            <div class="si">Transport: <strong>WebSocket wss://</strong></div>
        </div>
    </div>

    <!-- RIGHT SIDE -->
    <div class="side">

        <div class="stats">
            <div class="stat s-p"><div class="stat-v" id="passEl">0</div><div class="stat-l">Passed</div></div>
            <div class="stat s-f"><div class="stat-v" id="failEl">0</div><div class="stat-l">Failed</div></div>
            <div class="stat s-t"><div class="stat-v" id="totalEl">—</div><div class="stat-l">Total</div></div>
        </div>

        <div class="steps-card">
            <div class="card-hd">
                <div class="card-t">Test Steps</div>
                <div class="card-s"><?= sanitize($run['suite_name']) ?></div>
            </div>
            <div class="steps-body" id="stepsEl"></div>
        </div>

        <div class="shots-card">
            <div class="shots-t">Screenshots</div>
            <div class="shots-row" id="shotsEl">
                <span class="placeholder">None yet</span>
            </div>
        </div>

        <div class="actions">
            <a href="/dashboard.php" class="btn btn-back">← Back</a>
            <button class="btn btn-danger" onclick="stopRun()">■ Stop Run</button>
        </div>

    </div>
</div>

<script src="/js/stream.js"></script>
<script>
const WS_URL   = <?= json_encode($wsUrl) ?>;
const RUN_ID   = <?= json_encode($runId) ?>;
const TOTAL    = <?= (int)($run['total_steps'] ?? 0) ?>;

// Show the img and hide placeholder as soon as first frame arrives
const imgEl   = document.getElementById('streamImg');
const phEl    = document.getElementById('streamPlaceholder');
let firstFrame = true;

const client = new TestStreamClient({
    wsUrl:       WS_URL,
    streamImg:   imgEl,
    stepsEl:     document.getElementById('stepsEl'),
    urlDisplay:  document.getElementById('addrText'),
    statusBadge: document.getElementById('statusBadge'),
    passEl:      document.getElementById('passEl'),
    failEl:      document.getElementById('failEl'),
    timeEl:      document.getElementById('timeEl'),
    fpsEl:       document.getElementById('fpsEl'),
    shotsEl:     document.getElementById('shotsEl'),
    onDone(summary) {
        document.getElementById('totalEl').textContent = summary.total;
        // Save final result indicator in sessionStorage
        try {
            sessionStorage.setItem('lastRun_' + RUN_ID, JSON.stringify({
                passed: summary.passed,
                failed: summary.failed,
                total:  summary.total
            }));
        } catch(_) {}
    }
});

// Hook: show img once first frame arrives
const origHandle = client._handle.bind(client);
client._handle = function(msg) {
    if (msg.type === 'frame' && firstFrame) {
        firstFrame = false;
        phEl.style.display  = 'none';
        imgEl.style.display = 'block';
    }
    origHandle(msg);
};

client.connect();

function stopRun() {
    if (confirm('Stop this test run?')) {
        client.disconnect();
        // POST stop to API
        fetch('/api/stop-run.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ run_id: RUN_ID })
        }).then(() => window.location.href = '/dashboard.php');
    }
}

// Cleanup on page leave
window.addEventListener('beforeunload', () => client.disconnect());
</script>
</body>
</html>
