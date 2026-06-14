<?php
/**
 * Shared helpers: sessions, CSRF, escaping, settings, money, logging.
 */
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/* ---------- Output escaping ---------- */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* ---------- CSRF protection ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function csrf_verify(?string $token): bool
{
    return !empty($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function require_csrf(): void
{
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!csrf_verify($token)) {
        http_response_code(419);
        die(json_encode(['ok' => false, 'error' => 'Invalid or expired security token. Please refresh.']));
    }
}

/* ---------- Settings (cached per request) ---------- */
function settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = db()->query('SELECT * FROM settings LIMIT 1')->fetch() ?: [];
    }
    return $cache;
}

function currency(): string
{
    return settings()['currency'] ?? 'KSh';
}

function money($amount): string
{
    return currency() . ' ' . number_format((float) $amount, 2);
}

/* ---------- Flash messages ---------- */
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Audit logging ---------- */
function audit(string $action, string $details = ''): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?,?,?,?)'
        );
        $stmt->execute([
            $_SESSION['user']['id'] ?? null,
            $action,
            mb_substr($details, 0, 500),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        // never let logging break the app
    }
}

/* ---------- JSON response helper ---------- */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/* ---------- Misc ---------- */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . '/' . ltrim($path, '/'));
    exit;
}

function old(string $key, $default = ''): string
{
    return e($_POST[$key] ?? $default);
}
