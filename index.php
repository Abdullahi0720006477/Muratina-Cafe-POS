<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error  = '';
$notice = '';
$loginMode = $_POST['login_mode'] ?? 'pin';   // 'pin' or 'staff'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? '')) {
        $error = 'Security token expired. Please try again.';
    } else {
        $do  = $_POST['do'] ?? 'login';
        $pin = $_POST['passcode'] ?? '';

        if ($do === 'login' && $loginMode === 'staff') {
            // Manager / Cashier — username + password
            if (attempt_login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) {
                if (!empty($_POST['remember'])) {
                    setcookie(session_name(), session_id(), time() + 60 * 60 * 24 * 30, '/');
                }
                redirect('dashboard.php');
            }
            $error = 'Invalid username or password.';
        } elseif ($do === 'login') {
            // PIN login (waiters and any staff with a passcode)
            if (attempt_passcode_login($pin)) {
                redirect(user_role() === 'waiter' ? 'pos.php' : 'dashboard.php');
            }
            $error = 'PIN not recognised.';
        } else {
            // Attendance actions — Clock In / Out / Break (do not start a session)
            $map = ['clock_in' => 'in', 'clock_out' => 'out', 'break' => 'break'];
            $type = $map[$do] ?? null;
            $u = $type ? user_by_passcode($pin) : null;
            if ($u) {
                db()->prepare('INSERT INTO attendance (user_id, type) VALUES (?,?)')->execute([$u['id'], $type]);
                $labels = ['in' => 'clocked in', 'out' => 'clocked out', 'break' => 'on break'];
                $notice = $u['full_name'] . ' — ' . $labels[$type] . ' at ' . date('H:i');
            } else {
                $error = 'Enter your PIN, then tap Clock In / Out / Break.';
            }
        }
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
    <!-- Cinematic background: themed muted videos that cross-fade.
         Drop your own clips in assets/videos/ (coffee.mp4, juice.mp4, meals.mp4);
         the poster images are shown automatically if a video is missing. -->
    <div class="login-bg" id="loginBg">
        <div class="bg-scene active" data-caption="Freshly brewed coffee" data-icon="fa-mug-hot">
            <video muted loop playsinline preload="auto"
                   poster="https://images.unsplash.com/photo-1511920170033-f8396924c348?w=1600&q=80">
                <source src="<?= BASE_URL ?>/assets/videos/coffee.mp4" type="video/mp4">
                <source src="https://cdn.coverr.co/videos/coverr-pouring-coffee-3853/1080p.mp4" type="video/mp4">
            </video>
            <div class="bg-fallback slide-1"></div>
        </div>
        <div class="bg-scene" data-caption="Cold-pressed fruit juices" data-icon="fa-glass-water">
            <video muted loop playsinline preload="none"
                   poster="https://images.unsplash.com/photo-1622597467836-f3285f2131b8?w=1600&q=80">
                <source src="<?= BASE_URL ?>/assets/videos/juice.mp4" type="video/mp4">
                <source src="https://cdn.coverr.co/videos/coverr-making-fresh-orange-juice-5180/1080p.mp4" type="video/mp4">
            </video>
            <div class="bg-fallback slide-2"></div>
        </div>
        <div class="bg-scene" data-caption="Delicious meals, served fresh" data-icon="fa-bowl-food">
            <video muted loop playsinline preload="none"
                   poster="https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=1600&q=80">
                <source src="<?= BASE_URL ?>/assets/videos/meals.mp4" type="video/mp4">
                <source src="https://cdn.coverr.co/videos/coverr-cooking-in-a-restaurant-2570/1080p.mp4" type="video/mp4">
            </video>
            <div class="bg-fallback slide-3"></div>
        </div>
    </div>
    <div class="login-overlay"></div>
    <div class="bg-caption" id="bgCaption"><i class="fa-solid fa-mug-hot"></i> <span>Freshly brewed coffee</span></div>

    <!-- POS terminal panel (YUMAPOS-style) -->
    <form method="post" class="pos-terminal" id="posForm" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="login_mode" id="loginMode" value="pin">
        <input type="hidden" name="passcode" id="passcode">

        <!-- Left: keypad / staff password -->
        <div class="pt-left">
            <div id="pinPanel">
                <div class="pin-screen" id="pinScreen" aria-live="polite"></div>
                <div class="keypad">
                    <?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?>
                        <button type="button" class="key" data-key="<?= $n ?>"><?= $n ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="key key-soft" data-key="back" aria-label="Backspace"><i class="fa-solid fa-arrow-left-long"></i></button>
                    <button type="button" class="key" data-key="0">0</button>
                    <button type="button" class="key key-soft" data-key="clear" aria-label="Clear">&times;</button>
                </div>
            </div>

            <div id="staffPanel" class="d-none">
                <div class="staff-fields">
                    <div class="input-icon mb-2"><i class="fa-solid fa-user"></i>
                        <input type="text" name="username" class="form-control form-control-lg" placeholder="Username" value="<?= old('username') ?>"></div>
                    <div class="input-icon mb-2"><i class="fa-solid fa-lock"></i>
                        <input type="password" name="password" class="form-control form-control-lg" placeholder="Password"></div>
                    <label class="remember"><input type="checkbox" name="remember"> Remember me</label>
                </div>
            </div>
        </div>

        <!-- Right: brand + action buttons -->
        <div class="pt-right">
            <div class="pt-brand">
                <span class="pt-logo"><i class="fa-solid fa-mug-hot"></i></span>
                <span class="pt-name"><?= e($set['company_name'] ?? 'Muratina Café') ?><small>Point of Sale</small></span>
            </div>

            <?php if ($timeout): ?><div class="pt-alert warn"><i class="fa-solid fa-clock"></i> Session expired. Sign in again.</div><?php endif; ?>
            <?php if ($error): ?><div class="pt-alert err"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div><?php endif; ?>
            <?php if ($notice): ?><div class="pt-alert ok"><i class="fa-solid fa-circle-check"></i> <?= e($notice) ?></div><?php endif; ?>

            <button type="submit" name="do" value="login" class="pos-btn primary" id="loginBtn"><i class="fa-solid fa-right-to-bracket"></i> LOGIN</button>
            <button type="submit" name="do" value="clock_in" class="pos-btn"><i class="fa-solid fa-business-time"></i> CLOCK IN</button>
            <button type="submit" name="do" value="clock_out" class="pos-btn"><i class="fa-solid fa-door-open"></i> CLOCK OUT</button>
            <button type="submit" name="do" value="break" class="pos-btn"><i class="fa-solid fa-mug-saucer"></i> BREAK</button>

            <a href="#" class="pt-toggle" id="toggleMode"><i class="fa-solid fa-user-tie"></i> <span>Manager / Cashier password login</span></a>
            <div class="pt-demo">Waiter PINs <code>1234</code> · <code>5678</code> — Staff <code>admin</code> / <code>Pass@123</code></div>
        </div>
    </form>

    <div class="slide-dots">
        <span class="active" data-i="0"></span><span data-i="1"></span><span data-i="2"></span>
    </div>
</div>

<script>
// ---- Keypad ----
(function () {
    let pin = '';
    const screen = document.getElementById('pinScreen');
    const field = document.getElementById('passcode');
    function refresh() { screen.textContent = '*'.repeat(pin.length); field.value = pin; }
    document.querySelectorAll('.key[data-key]').forEach(k => k.addEventListener('click', () => {
        const v = k.dataset.key;
        if (v === 'back') pin = pin.slice(0, -1);
        else if (v === 'clear') pin = '';
        else if (pin.length < 8) pin += v;
        refresh();
    }));
    document.addEventListener('keydown', e => {
        if (!document.getElementById('staffPanel').classList.contains('d-none')) return; // ignore in staff mode
        if (/^[0-9]$/.test(e.key) && pin.length < 8) { pin += e.key; refresh(); }
        else if (e.key === 'Backspace') { pin = pin.slice(0, -1); refresh(); }
    });
    refresh();
})();

// ---- Toggle PIN <-> Staff password ----
document.getElementById('toggleMode').addEventListener('click', function (e) {
    e.preventDefault();
    const staff = document.getElementById('staffPanel');
    const pinP = document.getElementById('pinPanel');
    const toStaff = staff.classList.contains('d-none');
    staff.classList.toggle('d-none', !toStaff);
    pinP.classList.toggle('d-none', toStaff);
    document.getElementById('loginMode').value = toStaff ? 'staff' : 'pin';
    this.querySelector('span').textContent = toStaff ? 'Use waiter PIN keypad' : 'Manager / Cashier password login';
    // Clock buttons only make sense in PIN mode
    document.querySelectorAll('.pos-btn:not(.primary)').forEach(b => b.style.display = toStaff ? 'none' : '');
});
<?php if ($loginMode === 'staff'): ?>document.getElementById('toggleMode').click();<?php endif; ?>

// ---- Cinematic background ----
(function () {
    const scenes = Array.from(document.querySelectorAll('.bg-scene'));
    const dots = Array.from(document.querySelectorAll('.slide-dots span'));
    const caption = document.getElementById('bgCaption');
    let i = 0;
    function show(n) {
        i = (n + scenes.length) % scenes.length;
        scenes.forEach((s, idx) => {
            const on = idx === i; s.classList.toggle('active', on);
            const v = s.querySelector('video'); if (v) { on ? v.play().catch(() => {}) : v.pause(); }
        });
        dots.forEach((d, idx) => d.classList.toggle('active', idx === i));
        const sc = scenes[i];
        caption.classList.remove('show');
        setTimeout(() => {
            caption.innerHTML = '<i class="fa-solid ' + sc.dataset.icon + '"></i> <span>' + sc.dataset.caption + '</span>';
            caption.classList.add('show');
        }, 350);
    }
    dots.forEach(d => d.addEventListener('click', () => show(+d.dataset.i)));
    setInterval(() => show(i + 1), 7000);
    show(0);
})();
</script>
</body>
</html>
