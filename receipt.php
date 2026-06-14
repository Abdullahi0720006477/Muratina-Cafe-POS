<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$no  = trim($_GET['no'] ?? '');

$stmt = $pdo->prepare(
    'SELECT s.*, u.full_name cashier, u.role served_role, c.name customer
     FROM sales s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN customers c ON c.id = s.customer_id
     WHERE s.receipt_no = ? LIMIT 1'
);
$stmt->execute([$no]);
$sale = $stmt->fetch();

if (!$sale) {
    http_response_code(404);
    die('Receipt not found.');
}

// Cashiers may only view their own receipts.
if (user_role() === 'cashier' && (int) $sale['user_id'] !== (int) current_user()['id']) {
    http_response_code(403);
    die('Access denied.');
}

$items = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = ?');
$items->execute([$sale['id']]);
$items = $items->fetchAll();

$set = settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt <?= e($sale['receipt_no']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>body{background:#ece8e1;padding:1rem}</style>
</head>
<body>
<div class="text-center mb-3 d-print-none">
    <button class="btn btn-brand" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/api/receipt_pdf.php?no=<?= e($sale['receipt_no']) ?>" target="_blank"><i class="fa-solid fa-file-pdf"></i> PDF / Download</a>
    <a class="btn btn-success" target="_blank"
       href="https://wa.me/?text=<?= urlencode(($set['company_name'] ?? 'Muratina Café') . " receipt " . $sale['receipt_no'] . " — Total: " . money($sale['total'])) ?>">
        <i class="fa-brands fa-whatsapp"></i> WhatsApp
    </a>
    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/pos.php"><i class="fa-solid fa-arrow-left"></i> Back to POS</a>
</div>

<div class="receipt" id="receipt">
    <div class="login-logo" style="margin:0 auto .5rem;width:54px;height:54px;font-size:1.4rem"><i class="fa-solid fa-mug-hot"></i></div>
    <h3><?= e($set['company_name'] ?? 'Muratina Café') ?></h3>
    <div class="r-center"><?= e($set['address'] ?? '') ?></div>
    <div class="r-center"><?= e($set['phone'] ?? '') ?></div>
    <div class="r-center"><?= e($set['email'] ?? '') ?></div>
    <div class="r-line"></div>

    <table>
        <tr><td>Receipt #</td><td style="text-align:right"><?= e($sale['receipt_no']) ?></td></tr>
        <tr><td>Date</td><td style="text-align:right"><?= e(date('d M Y H:i', strtotime($sale['created_at']))) ?></td></tr>
        <tr><td>Served by</td><td style="text-align:right"><?= e($sale['cashier'] ?? '—') ?><?= $sale['served_role'] ? ' (' . e(ucfirst($sale['served_role'])) . ')' : '' ?></td></tr>
        <tr><td>Customer</td><td style="text-align:right"><?= e($sale['customer'] ?? 'Walk-in') ?></td></tr>
    </table>
    <div class="r-line"></div>

    <table>
        <thead><tr><td><b>Item</b></td><td style="text-align:center"><b>Qty</b></td><td style="text-align:right"><b>Total</b></td></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?= e($it['product_name']) ?><br><small><?= money($it['price']) ?></small></td>
                <td style="text-align:center"><?= (int) $it['qty'] ?></td>
                <td style="text-align:right"><?= money($it['line_total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="r-line"></div>

    <table>
        <tr><td>Subtotal</td><td style="text-align:right"><?= money($sale['subtotal']) ?></td></tr>
        <?php if ($sale['discount'] > 0): ?>
            <tr><td>Discount</td><td style="text-align:right">- <?= money($sale['discount']) ?></td></tr>
        <?php endif; ?>
        <tr><td>Tax</td><td style="text-align:right"><?= money($sale['tax']) ?></td></tr>
        <tr style="font-size:1.1em;font-weight:bold"><td>TOTAL</td><td style="text-align:right"><?= money($sale['total']) ?></td></tr>
        <tr><td>Payment</td><td style="text-align:right"><?= e($sale['payment_method']) ?></td></tr>
    </table>
    <div class="r-line"></div>
    <div class="r-center"><?= e($set['receipt_footer'] ?? 'Thank you!') ?></div>
    <div class="r-center" style="margin-top:.5rem">★ ★ ★</div>
</div>
</body>
</html>
