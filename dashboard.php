<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$role = user_role();

// Cashiers and waiters see a slim, sales-focused dashboard scoped to themselves.
$ownFilter = in_array($role, ['cashier', 'waiter'], true)
    ? ' AND user_id = ' . (int) current_user()['id'] : '';

$todaySales   = $pdo->query("SELECT COALESCE(SUM(total),0) FROM sales WHERE DATE(created_at)=CURDATE()$ownFilter")->fetchColumn();
$monthSales   = $pdo->query("SELECT COALESCE(SUM(total),0) FROM sales WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())$ownFilter")->fetchColumn();
$totalOrders  = $pdo->query("SELECT COUNT(*) FROM sales WHERE 1$ownFilter")->fetchColumn();
$totalProducts = $pdo->query("SELECT COUNT(*) FROM products WHERE is_active=1")->fetchColumn();
$lowStock     = $pdo->query("SELECT COUNT(*) FROM products WHERE stock_qty <= low_stock AND is_active=1")->fetchColumn();
$activeUsers  = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();

// Daily sales — last 7 days
$daily = $pdo->query(
    "SELECT DATE(created_at) d, SUM(total) t FROM sales
     WHERE created_at >= CURDATE() - INTERVAL 6 DAY $ownFilter
     GROUP BY DATE(created_at)"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$dayLabels = []; $dayData = [];
for ($k = 6; $k >= 0; $k--) {
    $d = date('Y-m-d', strtotime("-$k day"));
    $dayLabels[] = date('D', strtotime($d));
    $dayData[]   = (float) ($daily[$d] ?? 0);
}

// Top products by revenue
$topProducts = $pdo->query(
    "SELECT product_name, SUM(line_total) rev FROM sale_items
     GROUP BY product_name ORDER BY rev DESC LIMIT 6"
)->fetchAll();

// Monthly revenue trend — last 6 months
$trend = $pdo->query(
    "SELECT DATE_FORMAT(created_at,'%Y-%m') m, SUM(total) t FROM sales
     WHERE created_at >= CURDATE() - INTERVAL 5 MONTH
     GROUP BY DATE_FORMAT(created_at,'%Y-%m')"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$trendLabels = []; $trendData = [];
for ($k = 5; $k >= 0; $k--) {
    $m = date('Y-m', strtotime("first day of -$k month"));
    $trendLabels[] = date('M', strtotime($m . '-01'));
    $trendData[]   = (float) ($trend[$m] ?? 0);
}

// Recent sales
$recent = $pdo->query(
    "SELECT s.receipt_no, s.total, s.payment_method, s.created_at, u.full_name
     FROM sales s LEFT JOIN users u ON u.id = s.user_id
     WHERE 1 $ownFilter ORDER BY s.id DESC LIMIT 8"
)->fetchAll();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="kpi-grid">
    <div class="kpi"><div class="kpi-icon bg-c1"><i class="fa-solid fa-sack-dollar"></i></div>
        <div class="kpi-value"><?= money($todaySales) ?></div><div class="kpi-label">Today's Sales</div></div>
    <div class="kpi"><div class="kpi-icon bg-c2"><i class="fa-solid fa-calendar-day"></i></div>
        <div class="kpi-value"><?= money($monthSales) ?></div><div class="kpi-label">Monthly Sales</div></div>
    <div class="kpi"><div class="kpi-icon bg-c3"><i class="fa-solid fa-receipt"></i></div>
        <div class="kpi-value"><?= number_format($totalOrders) ?></div><div class="kpi-label">Total Orders</div></div>
    <div class="kpi"><div class="kpi-icon bg-c4"><i class="fa-solid fa-box"></i></div>
        <div class="kpi-value"><?= number_format($totalProducts) ?></div><div class="kpi-label">Total Products</div></div>
    <div class="kpi"><div class="kpi-icon bg-c5"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="kpi-value"><?= number_format($lowStock) ?></div><div class="kpi-label">Low Stock Items</div></div>
    <div class="kpi"><div class="kpi-icon bg-c6"><i class="fa-solid fa-users"></i></div>
        <div class="kpi-value"><?= number_format($activeUsers) ?></div><div class="kpi-label">Active Users</div></div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3"><div class="card-body">
            <h3 class="section-title">Daily Sales — Last 7 Days</h3>
            <canvas id="dailyChart" height="110"></canvas>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <h3 class="section-title">Top Products</h3>
            <canvas id="topChart" height="200"></canvas>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-3"><div class="card-body">
            <h3 class="section-title">Revenue Trend — Last 6 Months</h3>
            <canvas id="trendChart" height="120"></canvas>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card table-card mb-3"><div class="card-body">
            <h3 class="section-title">Recent Sales</h3>
            <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>Receipt</th><th>Cashier</th><th>Method</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                <?php if (!$recent): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No sales yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><a href="<?= BASE_URL ?>/receipt.php?no=<?= e($r['receipt_no']) ?>"><?= e($r['receipt_no']) ?></a></td>
                        <td><?= e($r['full_name'] ?? '—') ?></td>
                        <td><span class="badge-soft badge-warn"><?= e($r['payment_method']) ?></span></td>
                        <td class="text-end fw-bold"><?= money($r['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div></div>
    </div>
</div>

<script>
const gridColor = getComputedStyle(document.body).getPropertyValue('--border');
const textColor = getComputedStyle(document.body).getPropertyValue('--muted');
Chart.defaults.color = textColor;
Chart.defaults.borderColor = gridColor;

new Chart(document.getElementById('dailyChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($dayLabels) ?>,
        datasets: [{ label: 'Sales', data: <?= json_encode($dayData) ?>,
            backgroundColor: '#b5651d', borderRadius: 8, maxBarThickness: 42 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});

new Chart(document.getElementById('topChart'), {
    type: 'doughnut',
    data: { labels: <?= json_encode(array_column($topProducts, 'product_name')) ?>,
        datasets: [{ data: <?= json_encode(array_map('floatval', array_column($topProducts, 'rev'))) ?>,
            backgroundColor: ['#b5651d','#e09f3e','#2a9d8f','#3a86ff','#8338ec','#e63946'] }] },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } } }
});

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: { labels: <?= json_encode($trendLabels) ?>,
        datasets: [{ label: 'Revenue', data: <?= json_encode($trendData) ?>,
            borderColor: '#e09f3e', backgroundColor: 'rgba(224,159,62,.15)', fill: true, tension: .35, pointRadius: 4 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
