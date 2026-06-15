<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$role = user_role();

if ($role === 'inventory') {
    // Inventory dashboard metrics
    $totalProducts   = $pdo->query("SELECT COUNT(*) FROM products WHERE is_active=1")->fetchColumn();
    $lowStockCount   = $pdo->query("SELECT COUNT(*) FROM products WHERE stock_qty <= low_stock AND stock_qty > 0 AND is_active=1")->fetchColumn();
    $outOfStock      = $pdo->query("SELECT COUNT(*) FROM products WHERE stock_qty = 0 AND is_active=1")->fetchColumn();
    $totalCategories = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $totalSuppliers  = $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
    $stockValue      = $pdo->query("SELECT COALESCE(SUM(stock_qty * purchase_price),0) FROM products WHERE is_active=1")->fetchColumn();

    // Stock value by category for doughnut chart
    $catValueData = $pdo->query(
        "SELECT c.name cat_name, COALESCE(SUM(p.stock_qty * p.purchase_price),0) val
         FROM products p
         JOIN categories c ON c.id = p.category_id
         WHERE p.is_active = 1
         GROUP BY c.id
         ORDER BY val DESC
         LIMIT 6"
    )->fetchAll();

    // Critical low stock table
    $criticalStock = $pdo->query(
        "SELECT name, stock_qty, low_stock FROM products
         WHERE stock_qty <= low_stock AND is_active=1
         ORDER BY stock_qty LIMIT 8"
    )->fetchAll();

    // Recent stock movements table
    $stockMovements = $pdo->query(
        "SELECT m.*, p.name pname, u.full_name uname FROM stock_movements m
         LEFT JOIN products p ON p.id = m.product_id
         LEFT JOIN users u ON u.id = m.user_id
         ORDER BY m.id DESC LIMIT 8"
    )->fetchAll();
} else {
    // Cashiers and waiters see a slim, sales-focused dashboard scoped to themselves.
    $ownFilter = in_array($role, ['cashier', 'waiter'], true)
        ? ' AND s.user_id = ' . (int) current_user()['id'] : '';
    $ownFilterNoTable = in_array($role, ['cashier', 'waiter'], true)
        ? ' AND user_id = ' . (int) current_user()['id'] : '';

    $todaySales   = $pdo->query("SELECT COALESCE(SUM(total),0) FROM sales WHERE DATE(created_at)=CURDATE()$ownFilterNoTable")->fetchColumn();
    $monthSales   = $pdo->query("SELECT COALESCE(SUM(total),0) FROM sales WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())$ownFilterNoTable")->fetchColumn();
    $totalOrders  = $pdo->query("SELECT COUNT(*) FROM sales WHERE 1$ownFilterNoTable")->fetchColumn();
    $totalProducts = $pdo->query("SELECT COUNT(*) FROM products WHERE is_active=1")->fetchColumn();
    $lowStock     = $pdo->query("SELECT COUNT(*) FROM products WHERE stock_qty <= low_stock AND is_active=1")->fetchColumn();
    $activeUsers  = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();

    // Daily sales — last 7 days
    $daily = $pdo->query(
        "SELECT DATE(created_at) d, SUM(total) t FROM sales
         WHERE created_at >= CURDATE() - INTERVAL 6 DAY $ownFilterNoTable
         GROUP BY DATE(created_at)"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $dayLabels = []; $dayData = [];
    for ($k = 6; $k >= 0; $k--) {
        $d = date('Y-m-d', strtotime("-$k day"));
        $dayLabels[] = date('D', strtotime($d));
        $dayData[]   = (float) ($daily[$d] ?? 0);
    }

    // Top products by revenue (scoped to user if cashier/waiter)
    $topProducts = $pdo->query(
        "SELECT si.product_name, SUM(si.line_total) rev 
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         WHERE 1 $ownFilter
         GROUP BY si.product_name ORDER BY rev DESC LIMIT 6"
    )->fetchAll();

    // Monthly revenue trend — last 6 months (scoped to user if cashier/waiter)
    $trend = $pdo->query(
        "SELECT DATE_FORMAT(created_at,'%Y-%m') m, SUM(total) t FROM sales
         WHERE created_at >= CURDATE() - INTERVAL 5 MONTH $ownFilterNoTable
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
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<?php if ($role === 'inventory'): ?>
    <!-- ============================================================
       INVENTORY OFFICER DASHBOARD
       ============================================================ -->
    <div class="kpi-grid">
        <div class="kpi"><div class="kpi-icon bg-c4"><i class="fa-solid fa-box"></i></div>
            <div class="kpi-value"><?= number_format($totalProducts) ?></div><div class="kpi-label">Active Products</div></div>
        <div class="kpi"><div class="kpi-icon bg-c5"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="kpi-value"><?= number_format($lowStockCount) ?></div><div class="kpi-label">Low Stock Items</div></div>
        <div class="kpi"><div class="kpi-icon bg-c1"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div class="kpi-value"><?= number_format($outOfStock) ?></div><div class="kpi-label">Out of Stock</div></div>
        <div class="kpi"><div class="kpi-icon bg-c3"><i class="fa-solid fa-tags"></i></div>
            <div class="kpi-value"><?= number_format($totalCategories) ?></div><div class="kpi-label">Categories</div></div>
        <div class="kpi"><div class="kpi-icon bg-c6"><i class="fa-solid fa-truck"></i></div>
            <div class="kpi-value"><?= number_format($totalSuppliers) ?></div><div class="kpi-label">Suppliers</div></div>
        <div class="kpi"><div class="kpi-icon bg-c2"><i class="fa-solid fa-vault"></i></div>
            <div class="kpi-value"><?= money($stockValue) ?></div><div class="kpi-label">Stock Capital Value</div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card mb-3"><div class="card-body">
                <h3 class="section-title">Stock Value by Category</h3>
                <canvas id="catValueChart" height="220"></canvas>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card table-card mb-3"><div class="card-body">
                <h3 class="section-title text-danger"><i class="fa-solid fa-triangle-exclamation"></i> Critical Low Stock</h3>
                <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Product</th><th class="text-end">Stock</th></tr></thead>
                    <tbody>
                    <?php if (!$criticalStock): ?>
                        <tr><td colspan="2" class="text-center text-muted py-3">All items are well stocked. 🎉</td></tr>
                    <?php endif; ?>
                    <?php foreach ($criticalStock as $cs): ?>
                        <tr>
                            <td><?= e($cs['name']) ?></td>
                            <td class="text-end"><span class="badge-soft badge-low"><?= (int) $cs['stock_qty'] ?> / <?= (int) $cs['low_stock'] ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card table-card mb-3"><div class="card-body">
                <h3 class="section-title"><i class="fa-solid fa-arrows-rotate"></i> Recent Movements</h3>
                <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Product</th><th>Type</th><th class="text-end">Qty</th></tr></thead>
                    <tbody>
                    <?php if (!$stockMovements): ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">No movements yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($stockMovements as $sm): ?>
                        <tr>
                            <td><small><?= e($sm['pname'] ?? '—') ?></small></td>
                            <td><span class="badge-soft <?= $sm['type'] === 'in' ? 'badge-ok' : 'badge-low' ?>"><?= e(ucfirst($sm['type'])) ?></span></td>
                            <td class="text-end fw-bold"><?= (int) $sm['qty'] ?></td>
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

    new Chart(document.getElementById('catValueChart'), {
        type: 'doughnut',
        data: { labels: <?= json_encode(array_column($catValueData, 'cat_name')) ?>,
            datasets: [{ data: <?= json_encode(array_map('floatval', array_column($catValueData, 'val'))) ?>,
                backgroundColor: ['#b5651d','#e09f3e','#2a9d8f','#3a86ff','#8338ec','#e63946'] }] },
        options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } } }
    });
    </script>

<?php else: ?>
    <!-- ============================================================
       SALES / REVENUE DASHBOARD (MANAGER, CASHIER, WAITER)
       ============================================================ -->
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
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
