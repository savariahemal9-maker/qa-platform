<?php
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/functions.php';
require_once __DIR__ . '/../app/controllers/AuthController.php';

$auth = new AuthController();
$auth->login();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login – QA Platform</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #0f172a;
    --surface: #1e293b;
    --border: #334155;
    --text: #f1f5f9;
    --muted: #94a3b8;
    --accent: #6366f1;
    --accent-hover: #4f46e5;
    --error: #f87171;
    --radius: 12px;
  }
  body {
    font-family: 'Inter', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 40px;
    width: 100%;
    max-width: 400px;
  }
  .logo {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 32px;
  }
  .logo-icon {
    width: 40px; height: 40px;
    background: var(--accent);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
  }
  .logo-text { font-size: 20px; font-weight: 700; }
  .logo-sub { font-size: 12px; color: var(--muted); }
  h1 { font-size: 22px; font-weight: 700; margin-bottom: 6px; }
  .subtitle { color: var(--muted); font-size: 14px; margin-bottom: 28px; }
  .field { margin-bottom: 18px; }
  label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px; color: var(--muted); }
  input {
    width: 100%;
    padding: 11px 14px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-size: 14px;
    font-family: inherit;
    transition: border-color .2s;
  }
  input:focus { outline: none; border-color: var(--accent); }
  .btn {
    width: 100%;
    padding: 12px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: background .2s;
    margin-top: 8px;
  }
  .btn:hover { background: var(--accent-hover); }
  .error {
    background: rgba(248,113,113,.1);
    border: 1px solid var(--error);
    color: var(--error);
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    margin-bottom: 18px;
  }
  .footer { text-align: center; margin-top: 24px; font-size: 12px; color: var(--muted); }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon">⚡</div>
    <div>
      <div class="logo-text">QA Platform</div>
      <div class="logo-sub">Automated Testing Suite</div>
    </div>
  </div>
  <h1>Welcome back</h1>
  <p class="subtitle">Sign in to your testing dashboard</p>

  <?php if (!empty($error)): ?>
    <div class="error"><?= sanitize($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="/login.php">
    <div class="field">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" placeholder="you@company.com"
             value="<?= sanitize($_POST['email'] ?? '') ?>" required autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" placeholder="••••••••" required>
    </div>
    <button type="submit" class="btn">Sign in →</button>
  </form>

  <div class="footer">QA Platform &copy; 2026 · Tracewave Transparency Pvt. Ltd.</div>
</div>
</body>
</html>
