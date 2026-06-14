<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $error = 'Security token expired. Please try again.';
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

        <form method="post" id="loginForm" autocomplete="off">
            <?= csrf_field() ?>
            <div class="mb-3 input-icon">
                <i class="fa-solid fa-user"></i>
                <input type="text" name="username" class="form-control form-control-lg" placeholder="Username" required autofocus value="<?= old('username') ?>">
            </div>
            <div class="mb-2 input-icon">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" id="password" class="form-control form-control-lg" placeholder="Password" required>
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

        <div class="demo-creds">
            <strong>Demo accounts</strong> (password: <code>Pass@123</code>)<br>
            admin · cashier · inventory
        </div>
    </div>

    <div class="slide-dots">
        <span class="active" data-i="0"></span><span data-i="1"></span><span data-i="2"></span>
    </div>
</div>

<script>
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
