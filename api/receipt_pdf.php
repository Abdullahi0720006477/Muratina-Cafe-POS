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
    'SELECT s.*, u.full_name cashier, u.role served_role, w.full_name waiter_name, c.name customer FROM sales s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN users w ON w.id = s.waiter_id
     LEFT JOIN customers c ON c.id = s.customer_id WHERE s.receipt_no = ? LIMIT 1'
);
$stmt->execute([$no]);
$sale = $stmt->fetch();
if (!$sale) { http_response_code(404); die('Receipt not found.'); }

if (in_array(user_role(), ['cashier', 'waiter'], true) && (int) $sale['user_id'] !== (int) current_user()['id']) {
    http_response_code(403); die('Access denied.');
}

$items = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = ?');
$items->execute([$sale['id']]);
$items = $items->fetchAll();
$set = settings();
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt <?= e($sale['receipt_no']) ?></title>
<style>
/* Thermal-printer layout — 80mm roll, bold and high-contrast */
@page { size: 80mm auto; margin: 0; }
* { box-sizing: border-box; }
body { font-family: 'Courier New', 'Consolas', monospace; font-size: 13px; font-weight: 700;
       width: 80mm; margin: 0 auto; padding: 4mm; color: #000; line-height: 1.5; }
h2 { text-align: center; margin: .2rem 0; font-size: 19px; font-weight: 800; letter-spacing: .5px; }
.c { text-align: center; }
.line { border-top: 2px dashed #000; margin: .45rem 0; }
table { width: 100%; border-collapse: collapse; }
td { padding: 2px 0; font-weight: 700; vertical-align: top; }
.r { text-align: right; }
.tot { font-weight: 800; font-size: 1.25em; }
.tot td { border-top: 2px solid #000; border-bottom: 2px solid #000; padding: 4px 0; }
.big { font-size: 15px; }
</style></head><body onload="window.print()">
<h2><?= e($set['company_name'] ?? 'Muratina Café') ?></h2>
<div class="c"><?= e($set['address'] ?? '') ?></div>
<div class="c"><?= e($set['phone'] ?? '') ?></div>
<?php if (!empty($set['email'])): ?><div class="c"><?= e($set['email']) ?></div><?php endif; ?>
<div class="line"></div>
<table>
<tr><td>Receipt</td><td class="r"><?= e($sale['receipt_no']) ?></td></tr>
<tr><td>Date</td><td class="r"><?= e(date('d M Y H:i', strtotime($sale['created_at']))) ?></td></tr>
<?php if (!empty($sale['waiter_name'])): ?>
<tr><td>Served by</td><td class="r"><?= e($sale['waiter_name']) ?> (Waiter)</td></tr>
<?php endif; ?>
<tr><td>Customer</td><td class="r"><?= e($sale['customer'] ?? 'Walk-in') ?></td></tr>
</table><div class="line"></div>
<table class="big">
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
<?php if (!empty($sale['waiter_name']) && $sale['commission_amount'] > 0): ?>
<table>
<tr><td>Waiter Commission (<?= (float)$sale['commission_rate'] ?>%)</td><td class="r"><?= money($sale['commission_amount']) ?></td></tr>
</table><div class="line"></div>
<?php endif; ?>
<div class="c"><?= e($set['receipt_footer'] ?? 'Thank you!') ?></div>
<div class="c">&nbsp;</div>
<div class="c">* * *</div>
</body></html>
