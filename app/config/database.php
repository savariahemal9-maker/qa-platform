<?php
/**
 * Database config + constants
 * All values loaded from environment variables (set in /etc/environment or .env)
 */

define('APP_URL',       getenv('APP_URL')       ?: 'http://localhost');
define('APP_NAME',      'QA Platform');
define('SESSION_NAME',  'qaplatform_session');
define('UPLOAD_PATH',   __DIR__ . '/../../storage/uploads');
define('ENGINE_HOST',   getenv('ENGINE_HOST')   ?: 'localhost');
define('ENGINE_PORT',   getenv('ENGINE_PORT')   ?: '3001');
define('ENGINE_SECRET', getenv('ENGINE_SECRET') ?: 'change_this_secret_in_production');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $name = getenv('DB_NAME') ?: 'qa_platform';
    $user = getenv('DB_USER') ?: 'qa_user';
    $pass = getenv('DB_PASS') ?: '';
    $port = getenv('DB_PORT') ?: '3306';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
