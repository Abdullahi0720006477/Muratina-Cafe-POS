<?php
/**
 * Authentication & role-based access control.
 */
require_once __DIR__ . '/functions.php';

/* ---------- Session timeout enforcement ---------- */
function enforce_timeout(): void
{
    if (isset($_SESSION['user'])) {
        $last = $_SESSION['last_activity'] ?? time();
        if (time() - $last > SESSION_TIMEOUT) {
            logout_user();
            redirect('index.php?timeout=1');
        }
        $_SESSION['last_activity'] = time();
    }
}

/* ---------- Current user ---------- */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function user_role(): string
{
    return $_SESSION['user']['role'] ?? '';
}

/* ---------- Guards ---------- */
function require_login(): void
{
    enforce_timeout();
    if (!is_logged_in()) {
        redirect('index.php');
    }
}

/**
 * Permission map per role. Manager has everything.
 */
function can(string $permission): bool
{
    $role = user_role();
    $matrix = [
        'manager'   => ['*'],
        'cashier'   => ['pos', 'sales_own', 'customers', 'receipt'],
        'inventory' => ['inventory', 'products', 'suppliers', 'stock', 'customers'],
    ];
    $perms = $matrix[$role] ?? [];
    return in_array('*', $perms, true) || in_array($permission, $perms, true);
}

function require_permission(string $permission): void
{
    require_login();
    if (!can($permission)) {
        http_response_code(403);
        die('<h2 style="font-family:sans-serif;padding:2rem">403 — You do not have permission to access this page.</h2>');
    }
}

/* ---------- Login / logout ---------- */
function attempt_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $ok = $user && (int) $user['is_active'] === 1
        && password_verify($password, $user['password_hash']);

    // record attempt
    $log = db()->prepare(
        'INSERT INTO login_history (user_id, username, ip_address, user_agent, success) VALUES (?,?,?,?,?)'
    );
    $log->execute([
        $user['id'] ?? null,
        $username,
        $_SERVER['REMOTE_ADDR'] ?? null,
        mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        $ok ? 1 : 0,
    ]);

    if (!$ok) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'        => (int) $user['id'],
        'full_name' => $user['full_name'],
        'username'  => $user['username'],
        'role'      => $user['role'],
    ];
    $_SESSION['last_activity'] = time();

    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
    audit('login', 'User logged in');

    return true;
}

function logout_user(): void
{
    audit('logout', 'User logged out');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
