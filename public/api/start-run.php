<?php
/**
 * api/start-run.php
 *
 * Called by the dashboard when user clicks "Run Test".
 * POST body (JSON): { suite_id: int, project_id: int }
 *
 * Flow:
 *  1. Validate session + ownership
 *  2. Load suite + test steps from DB
 *  3. Create test_run row with status = 'running'
 *  4. POST job to Node.js engine on port 3001
 *  5. Return { run_id, ws_url } so browser can navigate to /run.php?run_id=...
 */

require_once __DIR__ . '/../../app/config/database.php';
require_once __DIR__ . '/../../app/helpers/functions.php';
requireLogin();

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'POST only'], 405);
}

$body     = json_decode(file_get_contents('php://input'), true);
$suiteId  = (int)($body['suite_id']   ?? 0);
$projectId= (int)($body['project_id'] ?? 0);
$user     = currentUser();
$companyId= $user['company_id'];

if (!$suiteId || !$projectId) {
    jsonResponse(['error' => 'suite_id and project_id required'], 400);
}

$db = getDB();

// Load project (verify ownership)
$stmt = $db->prepare("SELECT * FROM projects WHERE id = ? AND company_id = ? LIMIT 1");
$stmt->execute([$projectId, $companyId]);
$project = $stmt->fetch();
if (!$project) jsonResponse(['error' => 'Project not found'], 404);

// Load suite
$stmt = $db->prepare("SELECT * FROM test_suites WHERE id = ? AND project_id = ? LIMIT 1");
$stmt->execute([$suiteId, $projectId]);
$suite = $stmt->fetch();
if (!$suite) jsonResponse(['error' => 'Suite not found'], 404);

// Load test steps (test_cases table)
$stmt = $db->prepare("SELECT * FROM test_cases WHERE suite_id = ? ORDER BY step_order ASC");
$stmt->execute([$suiteId]);
$steps = $stmt->fetchAll();

// Build run record
$runId = \Ramsey\Uuid\Uuid::uuid4()->toString();  // or use PHP fallback below

// UUID fallback if Ramsey not installed
function generateUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0xffff),
        mt_rand(0,0x0fff) | 0x4000,
        mt_rand(0,0x3fff) | 0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
    );
}
$runId = generateUUID();

// Insert test_run
$db->prepare("
    INSERT INTO test_runs (id, company_id, project_id, suite_id, triggered_by, status, total_steps, started_at)
    VALUES (?, ?, ?, ?, ?, 'running', ?, NOW())
")->execute([$runId, $companyId, $projectId, $suiteId, $user['id'], count($steps)]);

// Format steps for engine
$engineSteps = array_map(fn($s) => [
    'description' => $s['name'],
    'action'      => $s['action'],
    'selector'    => $s['selector']   ?? null,
    'value'       => $s['value']      ?? null,
    'url'         => $s['target_url'] ?? null,
    'timeout'     => $s['timeout_ms'] ?? 10000,
    'stopOnFail'  => (bool)($s['stop_on_fail'] ?? false),
], $steps);

// POST to engine
$engineHost   = defined('ENGINE_HOST') ? ENGINE_HOST : 'localhost';
$enginePort   = defined('ENGINE_PORT') ? ENGINE_PORT : 3001;
$engineSecret = defined('ENGINE_SECRET') ? ENGINE_SECRET : 'change_this_secret';
$engineUrl    = "http://{$engineHost}:{$enginePort}/run";

$payload = json_encode([
    'secret'    => $engineSecret,
    'runId'     => $runId,
    'targetUrl' => $project['url'],
    'suite'     => $suite['name'],
    'steps'     => $engineSteps,
]);

$ctx = stream_context_create([
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
        'content' => $payload,
        'timeout' => 5,
    ]
]);

$result  = @file_get_contents($engineUrl, false, $ctx);
$decoded = $result ? json_decode($result, true) : null;

if (!$decoded || !isset($decoded['runId'])) {
    // Engine unavailable — mark run as failed
    $db->prepare("UPDATE test_runs SET status = 'failed', finished_at = NOW() WHERE id = ?")
       ->execute([$runId]);
    jsonResponse(['error' => 'Test engine unavailable. Is port 3001 running?'], 503);
}

// All good — return to browser
jsonResponse([
    'run_id' => $runId,
    'ws_url' => $decoded['wsUrl'] ?? null,
    'redirect' => "/run.php?run_id={$runId}",
]);
