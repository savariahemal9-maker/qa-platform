# QA Platform

Automated browser testing SaaS with **live screenshot streaming** via WebSocket.

## How live testing works

```
Client Website
      ↓
Playwright (real Chrome) on EC2
      ↓  screenshot every 200ms
Node.js WebSocket server (:3001)
      ↓  JPEG frames via wss://
Browser (run.php)
      ↓
Client sees their real website being tested live
```

## Stack

| Layer | Tech |
|-------|------|
| Frontend | PHP 8.3, Nginx |
| Test Engine | Node.js 22, Playwright, WebSocket (`ws`) |
| Database | MySQL 8 (multi-tenant via `company_id`) |
| Deploy | EC2 t3.micro, Ubuntu 24.04, PM2 |

## File structure

```
engine/
  server.js          ← Node.js WebSocket server + Playwright runner
  package.json

public/
  login.php          ← Login page
  dashboard.php      ← Main dashboard (stats, recent runs, projects)
  run.php            ← Live test runner (WebSocket stream → <img>)
  js/stream.js       ← WebSocket client class
  api/
    start-run.php    ← PHP triggers engine job
    test-results.php ← Engine posts results back here

app/
  config/database.php
  controllers/AuthController.php
  helpers/functions.php

database/schema.sql  ← Full MySQL schema
nginx/qa-platform.conf
deploy/install.sh    ← One-command EC2 setup
```

## Deploy to EC2

```bash
# 1. SSH in
ssh -i qa-platform-key.pem ubuntu@13.233.193.92

# 2. Clone repo
git clone https://github.com/savariahemal9-maker/qa-platform /var/www/qa-platform

# 3. Run install script
cd /var/www/qa-platform
sudo bash deploy/install.sh

# 4. Set env vars in nginx config
sudo nano /etc/nginx/sites-available/qa-platform
# → update DB_PASS, ENGINE_SECRET

# 5. SSL
sudo certbot --nginx -d testingqa.hemalsavaria.com
```

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_HOST` | `127.0.0.1` | MySQL host |
| `DB_NAME` | `qa_platform` | Database name |
| `DB_USER` | `qa_user` | DB username |
| `DB_PASS` | — | DB password |
| `ENGINE_HOST` | `localhost` | Node engine host |
| `ENGINE_PORT` | `3001` | Node engine port |
| `ENGINE_SECRET` | — | Shared secret PHP↔Engine |

## WebSocket message protocol

Engine → Browser:

```json
{ "type": "frame",      "frame": "<base64-jpeg>", "ts": 1729000000000 }
{ "type": "step",       "index": 3, "text": "Click login button", "status": "pass", "duration": "0.3s" }
{ "type": "screenshot", "label": "after-login", "frame": "<base64-jpeg>" }
{ "type": "done",       "passed": 10, "failed": 1, "total": 11 }
{ "type": "error",      "message": "Timeout waiting for selector" }
```
