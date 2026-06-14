<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('reports');

$pdo = db();

$type = $_GET['type'] ?? 'daily';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');

/**
 * Build the report dataset based on selected type.
 * Returns [columns, rows, title].
 */
function build_report(PDO $pdo, string $type, string $from, string $to): array
{
    $range = [$from . ' 00:00:00', $to . ' 23:59:59'];

    switch ($type) {
        case 'product':
            $rows = $pdo->prepare(
                'SELECT si.product_name, SUM(si.qty) qty, SUM(si.line_total) revenue,
                        SUM((si.price-si.cost)*si.qty) profit
                 FROM sale_items si JOIN sales s ON s.id = si.sale_id
                 WHERE s.created_at BETWEEN ? AND ?
                 GROUP BY si.product_name ORDER BY revenue DESC');
            $rows->execute($range);
            return [['Product', 'Qty Sold', 'Revenue', 'Profit'], $rows->fetchAll(), 'Product Sales Report'];

        case 'inventory':
            $rows = $pdo->query(
                'SELECT p.name, c.name cat, p.stock_qty, p.low_stock, p.purchase_price,
                        (p.stock_qty*p.purchase_price) value FROM products p
                 LEFT JOIN categories c ON c.id=p.category_id WHERE p.is_active=1 ORDER BY p.name');
            return [['Product', 'Category', 'Stock', 'Low Mark', 'Unit Cost', 'Stock Value'], $rows->fetchAll(), 'Inventory Report'];

        case 'profit':
            $rows = $pdo->prepare(
                'SELECT DATE(s.created_at) day, SUM(si.line_total) revenue,
                        SUM(si.cost*si.qty) cost, SUM((si.price-si.cost)*si.qty) profit
                 FROM sales s JOIN sale_items si ON si.sale_id=s.id
                 WHERE s.created_at BETWEEN ? AND ? GROUP BY DATE(s.created_at) ORDER BY day');
            $rows->execute($range);
            return [['Date', 'Revenue', 'Cost', 'Profit'], $rows->fetchAll(), 'Profit Report'];

        case 'attendance':
            $rows = $pdo->prepare(
                "SELECT u.full_name, UPPER(a.type) action, DATE_FORMAT(a.created_at,'%Y-%m-%d') day,
                        DATE_FORMAT(a.created_at,'%H:%i') time
                 FROM attendance a JOIN users u ON u.id = a.user_id
                 WHERE a.created_at BETWEEN ? AND ? ORDER BY a.created_at DESC");
            $rows->execute($range);
            return [['Employee', 'Action', 'Date', 'Time'], $rows->fetchAll(), 'Attendance Report'];

        case 'cashier':
            $rows = $pdo->prepare(
                'SELECT u.full_name, COUNT(s.id) orders, SUM(s.total) sales
                 FROM sales s JOIN users u ON u.id=s.user_id
                 WHERE s.created_at BETWEEN ? AND ? GROUP BY u.id ORDER BY sales DESC');
            $rows->execute($range);
            return [['Cashier', 'Orders', 'Total Sales'], $rows->fetchAll(), 'Cashier Performance'];

        case 'monthly':
            $rows = $pdo->prepare(
                "SELECT DATE_FORMAT(created_at,'%Y-%m') period, COUNT(*) orders, SUM(total) sales
                 FROM sales WHERE created_at BETWEEN ? AND ? GROUP BY period ORDER BY period");
            $rows->execute($range);
            return [['Month', 'Orders', 'Total Sales'], $rows->fetchAll(), 'Monthly Sales Report'];

        case 'daily':
        default:
            $rows = $pdo->prepare(
                'SELECT DATE(created_at) period, COUNT(*) orders, SUM(subtotal) subtotal,
                        SUM(discount) discount, SUM(tax) tax, SUM(total) total
                 FROM sales WHERE created_at BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY period');
            $rows->execute($range);
            return [['Date', 'Orders', 'Subtotal', 'Discount', 'Tax', 'Total'], $rows->fetchAll(), 'Daily Sales Report'];
    }
}

[$columns, $rows, $title] = build_report($pdo, $type, $from, $to);

// ---- Exports ----
$export = $_GET['export'] ?? '';
if ($export) {
    $filename = preg_replace('/\s+/', '_', strtolower($title)) . '_' . date('Ymd');
    if ($export === 'csv' || $export === 'excel') {
        $ext = $export === 'excel' ? 'xls' : 'csv';
        $sep = $export === 'excel' ? "\t" : ',';
        header('Content-Type: ' . ($export === 'excel' ? 'application/vnd.ms-excel' : 'text/csv'));
        header("Content-Disposition: attachment; filename=\"$filename.$ext\"");
        $out = fopen('php://output', 'w');
        fputcsv($out, $columns, $sep);
        foreach ($rows as $r) { fputcsv($out, array_values($r), $sep); }
        fclose($out);
        exit;
    }
    if ($export === 'pdf') {
        // Lightweight printable HTML that the browser saves as PDF.
        $set = settings();
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . e($title) . '</title>';
        echo '<style>body{font-family:Arial;padding:30px}h1{color:#b5651d}table{width:100%;border-collapse:collapse;margin-top:1rem}th,td{border:1px solid #ccc;padding:8px;text-align:left}th{background:#b5651d;color:#fff}</style></head><body>';
        echo '<h1>' . e($set['company_name'] ?? 'Muratina Café') . '</h1><h3>' . e($title) . '</h3>';
        echo '<p>Period: ' . e($from) . ' to ' . e($to) . '</p><table><thead><tr>';
        foreach ($columns as $c) { echo '<th>' . e($c) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) { echo '<tr>'; foreach ($r as $v) { echo '<td>' . e($v) . '</td>'; } echo '</tr>'; }
        echo '</tbody></table><script>window.print()</script></body></html>';
        exit;
    }
}

$pageTitle = 'Reports';
$activeNav = 'reports';
require __DIR__ . '/includes/header.php';

$reportTypes = [
    'daily' => 'Daily Sales', 'monthly' => 'Monthly Sales', 'product' => 'Product Sales',
    'inventory' => 'Inventory', 'profit' => 'Profit', 'cashier' => 'Cashier Performance',
    'attendance' => 'Attendance',
];
$qs = fn(array $extra) => '?' . http_build_query(array_merge(['type' => $type, 'from' => $from, 'to' => $to], $extra));
?>
<div class="card mb-3"><div class="card-body">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-3"><label class="form-label">Report Type</label>
            <select name="type" class="form-select">
                <?php foreach ($reportTypes as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-md-3"><label class="form-label">From</label><input type="date" name="from" value="<?= e($from) ?>" class="form-control"></div>
        <div class="col-md-3"><label class="form-label">To</label><input type="date" name="to" value="<?= e($to) ?>" class="form-control"></div>
        <div class="col-md-3"><button class="btn btn-brand w-100"><i class="fa-solid fa-filter"></i> Generate</button></div>
    </form>
</div></div>

<div class="d-flex justify-content-between align-items-center mb-2">
    <h3 class="section-title mb-0"><?= e($title) ?></h3>
    <div class="btn-group">
        <a class="btn btn-outline-secondary btn-sm" href="<?= $qs(['export' => 'csv']) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= $qs(['export' => 'excel']) ?>"><i class="fa-solid fa-file-excel"></i> Excel</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= $qs(['export' => 'pdf']) ?>" target="_blank"><i class="fa-solid fa-file-pdf"></i> PDF</a>
    </div>
</div>

<div class="card table-card"><div class="table-responsive">
<table class="table align-middle mb-0">
    <thead><tr><?php foreach ($columns as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $i = 0; ?>
        <tr><?php foreach ($r as $v): $isMoney = $i > 0 && is_numeric($v) && stripos($columns[$i], 'order') === false && stripos($columns[$i], 'qty') === false && stripos($columns[$i], 'stock') === false && stripos($columns[$i], 'mark') === false; ?>
            <td><?= $isMoney ? money($v) : e($v) ?></td>
        <?php $i++; endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= count($columns) ?>" class="text-center text-muted py-4">No data for this period.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
