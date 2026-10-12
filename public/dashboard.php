<?php
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/functions.php';
requireLogin();

$user = currentUser();
$db   = getDB();

// Stats
$stats = $db->prepare("
    SELECT
        (SELECT COUNT(*) FROM projects      WHERE company_id = ?) AS total_projects,
        (SELECT COUNT(*) FROM test_suites   ts
           JOIN projects p ON p.id = ts.project_id
          WHERE p.company_id = ?)                                    AS total_suites,
        (SELECT COUNT(*) FROM test_runs     WHERE company_id = ?)   AS total_runs,
        (SELECT COUNT(*) FROM test_runs     WHERE company_id = ? AND status = 'passed') AS passed_runs,
        (SELECT COUNT(*) FROM test_runs     WHERE company_id = ? AND status = 'failed') AS failed_runs
");
$stats->execute(array_fill(0, 5, $user['company_id']));
$s = $stats->fetch();

// Recent runs
$runsStmt = $db->prepare("
    SELECT tr.*, p.name AS project_name, p.url AS project_url, ts.name AS suite_name
    FROM test_runs tr
    JOIN projects p     ON p.id  = tr.project_id
    JOIN test_suites ts ON ts.id = tr.suite_id
    WHERE tr.company_id = ?
    ORDER BY tr.started_at DESC
    LIMIT 10
");
$runsStmt->execute([$user['company_id']]);
$runs = $runsStmt->fetchAll();

// Projects
$projStmt = $db->prepare("SELECT * FROM projects WHERE company_id = ? ORDER BY name");
$projStmt->execute([$user['company_id']]);
$projects = $projStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard – QA Platform</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--bg:#07090f;--surf:#0d1117;--panel:#111827;--card:#161e2e;--border:#1c2a3e;--bord2:#253350;--text:#e4ecf7;--muted:#4a6580;--acc:#7c6fff;--pass:#22d3a0;--fail:#f43f5e;--warn:#fbbf24;--acclo:rgba(124,111,255,.1);--passbg:rgba(34,211,160,.08);--failbg:rgba(244,63,94,.06);color-scheme:dark}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}

/* sidebar */
.sidebar{width:220px;flex-shrink:0;background:var(--surf);border-right:1px solid var(--border);display:flex;flex-direction:column;padding:16px 0;min-height:100vh}
.sb-logo{display:flex;align-items:center;gap:9px;padding:0 16px 20px}
.sb-logo-box{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--acc),#6366f1);display:flex;align-items:center;justify-content:center;font-size:16px}
.sb-logo-name{font-size:14px;font-weight:700}
.sb-logo-company{font-size:11px;color:var(--muted)}
.sb-section{padding:0 10px;margin-bottom:4px}
.sb-section-label{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;padding:6px 6px 2px;font-weight:600}
.sb-link{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:8px;font-size:13px;color:var(--muted);text-decoration:none;transition:all .15s}
.sb-link:hover{background:var(--panel);color:var(--text)}
.sb-link.active{background:var(--acclo);color:var(--acc);font-weight:500}
.sb-link .ico{width:18px;text-align:center;flex-shrink:0}
.sb-spacer{flex:1}
.sb-user{padding:12px 16px;border-top:1px solid var(--border)}
.sb-user-name{font-size:12px;font-weight:500}
.sb-user-email{font-size:11px;color:var(--muted)}
.sb-user-logout{font-size:11px;color:var(--fail);text-decoration:none;margin-top:4px;display:inline-block}

/* main content */
.content{flex:1;overflow:auto;padding:20px 24px}
.page-hd{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px}
.page-title{font-size:20px;font-weight:700}
.page-sub{font-size:13px;color:var(--muted);margin-top:2px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;border:none;text-decoration:none;transition:all .15s}
.btn-primary{background:var(--acc);color:#fff}
.btn-primary:hover{opacity:.85}

/* stats grid */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:16px}
.stat-card-v{font-size:28px;font-weight:700;font-family:'JetBrains Mono',monospace;line-height:1;margin-bottom:4px}
.stat-card-l{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.sc-projects .stat-card-v{color:var(--acc)}
.sc-pass .stat-card-v{color:var(--pass)}
.sc-fail .stat-card-v{color:var(--fail)}
.sc-total .stat-card-v{color:var(--warn)}

/* section heading */
.sec-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.sec-title{font-size:14px;font-weight:600}
.sec-link{font-size:12px;color:var(--acc);text-decoration:none}

/* runs table */
.table-wrap{background:var(--panel);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:20px}
table{width:100%;border-collapse:collapse}
th{padding:10px 14px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);text-align:left;border-bottom:1px solid var(--border);background:var(--surf)}
td{padding:11px 14px;font-size:13px;border-bottom:1px solid var(--border)}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.015)}
.run-status{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:5px;font-size:11px;font-family:'JetBrains Mono',monospace}
.rs-pass{background:var(--passbg);color:var(--pass);border:1px solid var(--pass)}
.rs-fail{background:var(--failbg);color:var(--fail);border:1px solid var(--fail)}
.rs-run{background:var(--acclo);color:var(--acc);border:1px solid var(--acc)}
.rs-q{background:rgba(251,191,36,.08);color:var(--warn);border:1px solid var(--warn)}
.run-link{color:var(--acc);text-decoration:none;font-size:12px}
.run-link:hover{text-decoration:underline}

/* projects grid */
.projects-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
.proj-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:14px;cursor:pointer;transition:border-color .15s,transform .15s;text-decoration:none;display:block;color:var(--text)}
.proj-card:hover{border-color:var(--acc);transform:translateY(-1px)}
.proj-icon{font-size:22px;margin-bottom:8px}
.proj-name{font-size:14px;font-weight:600;margin-bottom:3px}
.proj-url{font-size:11px;color:var(--muted);font-family:'JetBrains Mono',monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.proj-meta{display:flex;gap:10px;margin-top:10px;font-size:11px;color:var(--muted)}

/* run button inline */
.run-btn{font-size:11px;padding:4px 10px;border-radius:5px;background:var(--acclo);color:var(--acc);border:1px solid var(--acc);cursor:pointer;font-family:'Inter',sans-serif;font-weight:500}
.run-btn:hover{background:var(--acc);color:#fff}

@media(max-width:700px){
    .sidebar{display:none}
    .content{padding:14px}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<nav class="sidebar">
    <div class="sb-logo">
        <div class="sb-logo-box">⚡</div>
        <div>
            <div class="sb-logo-name">QA Platform</div>
            <div class="sb-logo-company"><?= sanitize($user['company_name']) ?></div>
        </div>
    </div>
    <div class="sb-section">
        <div class="sb-section-label">Main</div>
        <a href="/dashboard.php" class="sb-link active"><span class="ico">📊</span> Dashboard</a>
        <a href="/projects.php"  class="sb-link"><span class="ico">📁</span> Projects</a>
        <a href="/suites.php"    class="sb-link"><span class="ico">🧪</span> Test Suites</a>
        <a href="/runs.php"      class="sb-link"><span class="ico">▶</span> Test Runs</a>
        <a href="/reports.php"   class="sb-link"><span class="ico">📈</span> Reports</a>
    </div>
    <div class="sb-section">
        <div class="sb-section-label">Account</div>
        <a href="/settings.php"  class="sb-link"><span class="ico">⚙️</span> Settings</a>
        <a href="/team.php"      class="sb-link"><span class="ico">👥</span> Team</a>
    </div>
    <div class="sb-spacer"></div>
    <div class="sb-user">
        <div class="sb-user-name"><?= sanitize($user['name']) ?></div>
        <div class="sb-user-email"><?= sanitize($user['email']) ?></div>
        <a href="/logout.php" class="sb-user-logout">Sign out</a>
    </div>
</nav>

<!-- CONTENT -->
<main class="content">
    <div class="page-hd">
        <div>
            <div class="page-title">Dashboard</div>
            <div class="page-sub">Welcome back, <?= sanitize($user['name']) ?></div>
        </div>
        <a href="/projects/new.php" class="btn btn-primary">+ New Project</a>
    </div>

    <!-- STATS -->
    <div class="stats">
        <div class="stat-card sc-projects">
            <div class="stat-card-v"><?= (int)$s['total_projects'] ?></div>
            <div class="stat-card-l">Projects</div>
        </div>
        <div class="stat-card sc-total">
            <div class="stat-card-v"><?= (int)$s['total_runs'] ?></div>
            <div class="stat-card-l">Total Runs</div>
        </div>
        <div class="stat-card sc-pass">
            <div class="stat-card-v"><?= (int)$s['passed_runs'] ?></div>
            <div class="stat-card-l">Passed</div>
        </div>
        <div class="stat-card sc-fail">
            <div class="stat-card-v"><?= (int)$s['failed_runs'] ?></div>
            <div class="stat-card-l">Failed</div>
        </div>
    </div>

    <!-- RECENT RUNS -->
    <div class="sec-hd">
        <div class="sec-title">Recent Test Runs</div>
        <a href="/runs.php" class="sec-link">View all →</a>
    </div>
    <div class="table-wrap" style="margin-bottom:20px">
        <table>
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Suite</th>
                    <th>Status</th>
                    <th>Steps</th>
                    <th>Started</th>
                    <th>Duration</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($runs)): ?>
                <tr><td colspan="7" style="color:var(--muted);text-align:center;padding:24px">No runs yet. Select a project and run a test suite.</td></tr>
            <?php else: ?>
                <?php foreach ($runs as $run): ?>
                <tr>
                    <td>
                        <div style="font-weight:500"><?= sanitize($run['project_name']) ?></div>
                        <div style="font-size:11px;color:var(--muted);font-family:'JetBrains Mono',monospace"><?= sanitize($run['project_url']) ?></div>
                    </td>
                    <td><?= sanitize($run['suite_name']) ?></td>
                    <td>
                        <?php
                        $sc = match($run['status']) {
                            'passed'  => 'rs-pass',
                            'failed'  => 'rs-fail',
                            'running' => 'rs-run',
                            default   => 'rs-q',
                        };
                        $icon = match($run['status']) {
                            'passed' => '✓', 'failed' => '✗', 'running' => '⟳', default => '○'
                        };
                        ?>
                        <span class="run-status <?= $sc ?>"><?= $icon ?> <?= ucfirst($run['status']) ?></span>
                    </td>
                    <td style="font-family:'JetBrains Mono',monospace">
                        <span style="color:var(--pass)"><?= (int)$run['passed_steps'] ?></span>
                        /
                        <span style="color:var(--muted)"><?= (int)$run['total_steps'] ?></span>
                    </td>
                    <td style="font-size:12px;color:var(--muted)"><?= $run['started_at'] ? date('d M, H:i', strtotime($run['started_at'])) : '—' ?></td>
                    <td style="font-size:12px;color:var(--muted)">
                        <?php
                        if ($run['started_at'] && $run['finished_at']) {
                            $dur = strtotime($run['finished_at']) - strtotime($run['started_at']);
                            echo $dur . 's';
                        } else {
                            echo '—';
                        }
                        ?>
                    </td>
                    <td>
                        <?php if ($run['status'] === 'running'): ?>
                            <a href="/run.php?run_id=<?= urlencode($run['id']) ?>" class="run-link">▶ Watch live</a>
                        <?php else: ?>
                            <a href="/results.php?run_id=<?= urlencode($run['id']) ?>" class="run-link">View results</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PROJECTS -->
    <div class="sec-hd">
        <div class="sec-title">Projects</div>
        <a href="/projects.php" class="sec-link">All projects →</a>
    </div>
    <div class="projects-grid">
        <?php foreach ($projects as $p): ?>
        <a href="/project.php?id=<?= urlencode($p['id']) ?>" class="proj-card">
            <div class="proj-icon">🌐</div>
            <div class="proj-name"><?= sanitize($p['name']) ?></div>
            <div class="proj-url"><?= sanitize($p['url']) ?></div>
            <div class="proj-meta">
                <span><?= (int)$p['suite_count'] ?? '—' ?> suites</span>
                <span><?= ucfirst($p['environment'] ?? 'prod') ?></span>
            </div>
        </a>
        <?php endforeach; ?>
        <?php if (empty($projects)): ?>
        <a href="/projects/new.php" class="proj-card" style="border-style:dashed;text-align:center;padding:24px">
            <div style="font-size:28px;margin-bottom:8px">+</div>
            <div class="proj-name">Add First Project</div>
            <div class="proj-url">Enter your website URL</div>
        </a>
        <?php endif; ?>
    </div>

</main>

<script>
// "Run" buttons in the table call this
async function startRun(suiteId, projectId) {
    const btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Starting…';

    const res  = await fetch('/api/start-run.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ suite_id: suiteId, project_id: projectId })
    });
    const data = await res.json();

    if (data.redirect) {
        window.location.href = data.redirect;
    } else {
        alert('Error: ' + (data.error || 'Unknown'));
        btn.disabled = false;
        btn.textContent = '▶ Run';
    }
}
</script>
</body>
</html>
