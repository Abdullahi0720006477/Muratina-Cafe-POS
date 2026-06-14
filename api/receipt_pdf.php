<?php
/**
 * Printable receipt — opens a clean print dialog the browser saves as PDF.
 * (Avoids heavy PDF libraries so it runs on a stock Laragon install.)
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$no  = trim($_GET['no'] ?? '');

$stmt = $pdo->prepare(
    'SELECT s.*, u.full_name cashier, c.name customer FROM sales s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN customers c ON c.id = s.customer_id WHERE s.receipt_no = ? LIMIT 1'
);
$stmt->execute([$no]);
$sale = $stmt->fetch();
if (!$sale) { http_response_code(404); die('Receipt not found.'); }

if (user_role() === 'cashier' && (int) $sale['user_id'] !== (int) current_user()['id']) {
    http_response_code(403); die('Access denied.');
}

$items = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = ?');
$items->execute([$sale['id']]);
$items = $items->fetchAll();
$set = settings();
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt <?= e($sale['receipt_no']) ?></title>
<style>
body{font-family:'Courier New',monospace;font-size:13px;max-width:320px;margin:0 auto;padding:14px;color:#000}
h2{text-align:center;margin:.2rem 0}.c{text-align:center}.line{border-top:1px dashed #000;margin:.5rem 0}
table{width:100%;border-collapse:collapse}td{padding:2px 0}.r{text-align:right}.tot{font-weight:bold;font-size:1.1em}
</style></head><body onload="window.print()">
<h2><?= e($set['company_name'] ?? 'Muratina Café') ?></h2>
<div class="c"><?= e($set['address'] ?? '') ?></div>
<div class="c"><?= e($set['phone'] ?? '') ?></div>
<div class="line"></div>
<table>
<tr><td>Receipt</td><td class="r"><?= e($sale['receipt_no']) ?></td></tr>
<tr><td>Date</td><td class="r"><?= e(date('d M Y H:i', strtotime($sale['created_at']))) ?></td></tr>
<tr><td>Cashier</td><td class="r"><?= e($sale['cashier'] ?? '—') ?></td></tr>
</table><div class="line"></div>
<table>
<?php foreach ($items as $it): ?>
<tr><td><?= e($it['product_name']) ?> x<?= (int) $it['qty'] ?></td><td class="r"><?= money($it['line_total']) ?></td></tr>
<?php endforeach; ?>
</table><div class="line"></div>
<table>
<tr><td>Subtotal</td><td class="r"><?= money($sale['subtotal']) ?></td></tr>
<?php if ($sale['discount'] > 0): ?><tr><td>Discount</td><td class="r">-<?= money($sale['discount']) ?></td></tr><?php endif; ?>
<tr><td>Tax</td><td class="r"><?= money($sale['tax']) ?></td></tr>
<tr class="tot"><td>TOTAL</td><td class="r"><?= money($sale['total']) ?></td></tr>
<tr><td>Paid via</td><td class="r"><?= e($sale['payment_method']) ?></td></tr>
</table><div class="line"></div>
<div class="c"><?= e($set['receipt_footer'] ?? 'Thank you!') ?></div>
</body></html>
