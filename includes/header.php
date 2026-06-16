<?php
/**
 * Authenticated layout header — sidebar + topbar.
 * Set $pageTitle and $activeNav before including.
 */
require_once __DIR__ . '/auth.php';
require_login();

$pageTitle = $pageTitle ?? 'Dashboard';
$activeNav = $activeNav ?? '';
$u         = current_user();
$set       = settings();

// Sidebar items: [key, label, icon, url, permission]
$nav = [
    ['dashboard', 'Dashboard',   'fa-gauge-high',     'dashboard.php', '*'],
    ['pos',       'POS Sales',   'fa-cash-register',  'pos.php',       'pos'],
    ['products',  'Products',    'fa-box',            'products.php',  'products'],
    ['categories','Categories',  'fa-tags',           'categories.php','products'],
    ['inventory', 'Inventory',   'fa-warehouse',      'inventory.php', 'inventory'],
    ['suppliers', 'Suppliers',   'fa-truck',          'suppliers.php', 'suppliers'],
    ['customers', 'Customers',   'fa-users',          'customers.php', 'customers'],
    ['reports',   'Reports',     'fa-chart-line',     'reports.php',   'reports'],
    ['users',     'User Mgmt',   'fa-user-shield',    'users.php',     'users'],
    ['salaries',  'Salaries',    'fa-wallet',         'salaries.php',  'settings'],
    ['settings',  'Settings',    'fa-gear',           'settings.php',  'settings'],
];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= e($set['theme_mode'] ?? 'dark') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> · <?= e($set['company_name'] ?? APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <?php if (!empty($set['primary_color']) || !empty($set['secondary_color'])): ?>
    <style>
    :root {
        <?php if (!empty($set['primary_color'])): ?>
        --brand: <?= e($set['primary_color']) ?>;
        <?php endif; ?>
        <?php if (!empty($set['secondary_color'])): ?>
        --brand-2: <?= e($set['secondary_color']) ?>;
        <?php endif; ?>
        --brand-grad: linear-gradient(135deg, <?= e($set['primary_color'] ?? '#b5651d') ?> 0%, <?= e($set['secondary_color'] ?? '#e09f3e') ?> 100%);
    }
    .nav-link.active {
        box-shadow: 0 6px 16px <?= e($set['primary_color'] ?? '#b5651d') ?>4d;
    }
    </style>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>window.BASE_URL = "<?= BASE_URL ?>"; window.CURRENCY = "<?= e(currency()) ?>";</script>
</head>
<body>
<div class="app-shell">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-mark"><i class="fa-solid fa-mug-hot"></i></span>
            <span class="brand-text"><?= e($set['company_name'] ?? 'Muratina Café') ?></span>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($nav as [$key, $label, $icon, $url, $perm]): ?>
                <?php if ($perm === '*' || can($perm)): ?>
                    <a href="<?= BASE_URL ?>/<?= $url ?>" class="nav-link <?= $activeNav === $key ? 'active' : '' ?>">
                        <i class="fa-solid <?= $icon ?>"></i><span><?= $label ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <a href="<?= BASE_URL ?>/logout.php" class="nav-link text-danger">
                <i class="fa-solid fa-right-from-bracket"></i><span>Logout</span>
            </a>
        </div>
    </aside>

    <!-- Main -->
    <div class="main-area">
        <header class="topbar glass">
            <button class="icon-btn" id="sidebarToggle" aria-label="Toggle menu"><i class="fa-solid fa-bars"></i></button>
            <h1 class="topbar-title"><?= e($pageTitle) ?></h1>
            <div class="topbar-actions">
                <button class="icon-btn" id="themeToggle" aria-label="Toggle theme"><i class="fa-solid fa-moon"></i></button>
                <div class="user-chip">
                    <div class="avatar"><?= e(strtoupper(substr($u['full_name'], 0, 1))) ?></div>
                    <div class="user-meta">
                        <strong><?= e($u['full_name']) ?></strong>
                        <small><?= e(ucfirst($u['role'])) ?></small>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">
            <?php foreach (get_flashes() as $f): ?>
                <div class="alert alert-<?= $f['type'] === 'error' ? 'danger' : e($f['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($f['msg']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
