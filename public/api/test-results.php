<?php
/**
 * api/test-results.php
 *
 * Called by the Node.js engine when a run finishes.
 * POST body (JSON): { secret, runId, passed, failed, total, results[] }
 *
 * Saves each step result to test_results and updates test_runs.status
 */

require_once __DIR__ . '/../../app/config/database.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$body    = json_decode(file_get_contents('php://input'), true);
$secret  = $body['secret'] ?? '';
$runId   = $body['runId']  ?? '';

$engineSecret = defined('ENGINE_SECRET') ? ENGINE_SECRET : 'change_this_secret';
if ($secret !== $engineSecret || !$runId) {
    http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT id, company_id FROM test_runs WHERE id = ? LIMIT 1");
$stmt->execute([$runId]);
$run  = $stmt->fetch();
if (!$run) { http_response_code(404); echo json_encode(['error'=>'Run not found']); exit; }

$passed  = (int)($body['passed'] ?? 0);
$failed  = (int)($body['failed'] ?? 0);
$total   = (int)($body['total']  ?? 0);
$status  = $failed > 0 ? 'failed' : 'passed';
$results = $body['results'] ?? [];

// Update test_run
$db->prepare("
    UPDATE test_runs
    SET status = ?, passed_steps = ?, failed_steps = ?, finished_at = NOW()
    WHERE id = ?
")->execute([$status, $passed, $failed, $runId]);

// Insert step results
$insStmt = $db->prepare("
    INSERT INTO test_results (run_id, company_id, step_index, description, status, error_message, duration)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
foreach ($results as $i => $r) {
    $insStmt->execute([
        $runId,
        $run['company_id'],
        $i,
        $r['step']     ?? '',
        $r['status']   ?? 'fail',
        $r['error']    ?? null,
        $r['duration'] ?? null,
    ]);
}

echo json_encode(['ok' => true, 'status' => $status]);
