<?php
/**
 * Application configuration — Muratina Café POS
 * Adjust DB credentials to match your Laragon MySQL setup.
 */

// ---- Database (Laragon defaults: user root, empty password) ----
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'muratina_pos');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// ---- App ----
define('APP_NAME', 'Muratina Café POS');
define('SESSION_TIMEOUT', 1800); // 30 minutes idle timeout
define('UPLOAD_DIR', __DIR__ . '/../uploads');

// Base URL helper — derives the app's URL path from the filesystem location of
// the project root relative to the web document root. Works whether the app is
// served at the docroot or from a subfolder (e.g. http://localhost/Muratinacaffe),
// and regardless of how deep the executing script is (root vs /api/).
$docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
$appRoot = str_replace('\\', '/', dirname(__DIR__)); // parent of /config
if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
    define('BASE_URL', rtrim(substr($appRoot, strlen($docRoot)), '/'));
} else {
    define('BASE_URL', '');
}

// ---- Errors: verbose locally, silent in production ----
error_reporting(E_ALL);
ini_set('display_errors', '1');

date_default_timezone_set('Africa/Nairobi');
