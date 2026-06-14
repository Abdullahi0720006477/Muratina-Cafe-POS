<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
$mode  = $_POST['mode'] ?? 'staff'; // 'staff' or 'waiter' — remembers active tab
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $error = 'Security token expired. Please try again.';
    } elseif ($mode === 'waiter') {
        // Waiters log in with their personal passcode (PIN).
        if (attempt_passcode_login($_POST['passcode'] ?? '')) {
            redirect('pos.php');
        }
        $error = 'Invalid passcode. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (attempt_login($username, $password)) {
            if (!empty($_POST['remember'])) {
                // extend session lifetime to 30 days
                $p = session_get_cookie_params();
                setcookie(session_name(), session_id(), time() + 60 * 60 * 24 * 30, '/');
            }
            redirect('dashboard.php');
        }
        $error = 'Invalid username or password.';
    }
}

$timeout = isset($_GET['timeout']);
$set = settings();

// Names of registered waiters shown on the PIN tab (each logs in by their own PIN).
$waiters = db()->query("SELECT full_name FROM users WHERE role='waiter' AND is_active=1 ORDER BY full_name")
    ->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login · <?= e($set['company_name'] ?? 'Muratina Café') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrap">
    <!-- Background slideshow: muted autoplay videos with image fallback -->
    <div class="login-bg">
        <video class="bg-video active" autoplay muted loop playsinline preload="auto"
               poster="https://images.unsplash.com/photo-1511920170033-f8396924c348?w=1600&q=80">
            <source src="https://cdn.coverr.co/videos/coverr-pouring-coffee-3853/1080p.mp4" type="video/mp4">
        </video>
        <video class="bg-video" muted loop playsinline preload="none"
               poster="https://images.unsplash.com/photo-1622597467836-f3285f2131b8?w=1600&q=80">
            <source src="https://cdn.coverr.co/videos/coverr-making-fresh-orange-juice-5180/1080p.mp4" type="video/mp4">
        </video>
        <video class="bg-video" muted loop playsinline preload="none"
               poster="https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=1600&q=80">
            <source src="https://cdn.coverr.co/videos/coverr-cooking-in-a-restaurant-2570/1080p.mp4" type="video/mp4">
        </video>
        <!-- Image fallback slides (used if videos cannot load) -->
        <div class="login-slide slide-1"></div>
        <div class="login-slide slide-2"></div>
        <div class="login-slide slide-3"></div>
    </div>
    <div class="login-overlay"></div>

    <div class="login-card">
        <div class="login-logo"><i class="fa-solid fa-mug-hot"></i></div>
        <h2><?= e($set['company_name'] ?? 'Muratina Café') ?></h2>
        <p class="subtitle">Restaurant &amp; Café Point of Sale</p>

        <?php if ($timeout): ?>
            <div class="alert alert-warning py-2"><i class="fa-solid fa-clock"></i> Session expired. Please sign in again.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
        <?php endif; ?>

        <!-- Login mode tabs -->
        <div class="login-tabs">
            <button type="button" class="login-tab <?= $mode !== 'waiter' ? 'active' : '' ?>" data-tab="staff">
                <i class="fa-solid fa-user-tie"></i> Manager / Cashier
            </button>
            <button type="button" class="login-tab <?= $mode === 'waiter' ? 'active' : '' ?>" data-tab="waiter">
                <i class="fa-solid fa-user-clock"></i> Waiter
            </button>
        </div>

        <!-- Staff login (username + password) -->
        <form method="post" id="loginForm" autocomplete="off" class="tab-pane <?= $mode !== 'waiter' ? '' : 'd-none' ?>" data-pane="staff">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="staff">
            <div class="mb-3 input-icon">
                <i class="fa-solid fa-user"></i>
                <input type="text" name="username" class="form-control form-control-lg" placeholder="Username" value="<?= old('username') ?>">
            </div>
            <div class="mb-2 input-icon">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" id="password" class="form-control form-control-lg" placeholder="Password">
            </div>
            <div class="login-extra">
                <label class="form-check-label" style="color:#fff">
                    <input type="checkbox" name="remember" class="form-check-input"> Remember me
                </label>
                <a href="#" onclick="alert('Please contact your manager to reset your password.');return false;">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-brand btn-login" id="loginBtn">
                <span class="btn-label"><i class="fa-solid fa-right-to-bracket"></i> Sign In</span>
                <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm"></span> Signing in…</span>
            </button>
        </form>

        <!-- Waiter login (passcode / PIN) -->
        <form method="post" id="waiterForm" class="tab-pane <?= $mode === 'waiter' ? '' : 'd-none' ?>" data-pane="waiter">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="waiter">
            <div class="pin-display" id="pinDisplay" aria-live="polite"></div>
            <input type="hidden" name="passcode" id="passcode">
            <div class="pin-pad">
                <?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?>
                    <button type="button" class="pin-key" data-key="<?= $n ?>"><?= $n ?></button>
                <?php endforeach; ?>
                <button type="button" class="pin-key pin-clear" data-key="clear"><i class="fa-solid fa-delete-left"></i></button>
                <button type="button" class="pin-key" data-key="0">0</button>
                <button type="submit" class="pin-key pin-enter"><i class="fa-solid fa-check"></i></button>
            </div>
            <?php if ($waiters): ?>
                <div class="waiter-list">Waiters: <?= e(implode(' · ', $waiters)) ?></div>
            <?php endif; ?>
        </form>

        <div class="demo-creds">
            <strong>Demo</strong> — Staff password: <code>Pass@123</code> (admin · cashier · inventory)<br>
            Waiter PINs: Brian <code>1234</code> · Aisha <code>5678</code>
        </div>
    </div>

    <div class="slide-dots">
        <span class="active" data-i="0"></span><span data-i="1"></span><span data-i="2"></span>
    </div>
</div>

<script>
// Tab switching between Staff and Waiter login
document.querySelectorAll('.login-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        const target = tab.dataset.tab;
        document.querySelectorAll('.login-tab').forEach(t => t.classList.toggle('active', t === tab));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('d-none', p.dataset.pane !== target));
    });
});

// Waiter PIN pad
(function () {
    let pin = '';
    const display = document.getElementById('pinDisplay');
    const field = document.getElementById('passcode');
    function refresh() {
        display.textContent = '●'.repeat(pin.length) || ' ';
        field.value = pin;
    }
    document.querySelectorAll('.pin-key[data-key]').forEach(k => {
        k.addEventListener('click', () => {
            const key = k.dataset.key;
            if (key === 'clear') pin = pin.slice(0, -1);
            else if (pin.length < 8) pin += key;
            refresh();
        });
    });
    // physical keyboard support on the waiter tab
    document.addEventListener('keydown', e => {
        if (document.querySelector('[data-pane="waiter"]').classList.contains('d-none')) return;
        if (/^[0-9]$/.test(e.key) && pin.length < 8) { pin += e.key; refresh(); }
        else if (e.key === 'Backspace') { pin = pin.slice(0, -1); refresh(); }
    });
    document.getElementById('waiterForm').addEventListener('submit', e => {
        if (!pin) { e.preventDefault(); display.textContent = 'Enter PIN'; }
    });
})();

// Loading state
document.getElementById('loginForm').addEventListener('submit', function () {
    document.querySelector('.btn-label').classList.add('d-none');
    document.querySelector('.btn-loading').classList.remove('d-none');
    document.getElementById('loginBtn').disabled = true;
});

// Background slideshow — rotates through videos; falls back to image slides
(function () {
    const videos = Array.from(document.querySelectorAll('.bg-video'));
    const slides = Array.from(document.querySelectorAll('.login-slide'));
    const dots = Array.from(document.querySelectorAll('.slide-dots span'));
    let i = 0;
    const useVideo = videos.length > 0;

    function show(n) {
        i = (n + 3) % 3;
        if (useVideo) {
            videos.forEach((v, idx) => {
                v.classList.toggle('active', idx === i);
                if (idx === i) { v.play().catch(() => {}); }
            });
        }
        slides.forEach((s, idx) => s.classList.toggle('active', idx === i && !useVideo));
        dots.forEach((d, idx) => d.classList.toggle('active', idx === i));
    }
    dots.forEach(d => d.addEventListener('click', () => show(+d.dataset.i)));

    // If first video errors, reveal image fallbacks instead
    videos.forEach(v => v.addEventListener('error', () => {
        videos.forEach(x => x.style.display = 'none');
        slides.forEach((s, idx) => s.classList.toggle('active', idx === i));
    }));

    setInterval(() => show(i + 1), 6000);
    show(0);
})();
</script>
</body>
</html>
