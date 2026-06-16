<?php
/**
 * Process a POS sale — atomic transaction.
 * Validates stock, recalculates totals server-side (never trust the client),
 * writes sale + items, decrements stock, records movements, awards loyalty.
 */
require_once __DIR__ . '/../includes/auth.php';
require_permission('pos');

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];

// CSRF from header
if (!csrf_verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    json_out(['ok' => false, 'error' => 'Invalid security token. Refresh the page.'], 419);
}

$items          = $input['items'] ?? [];
$discount       = max(0, (float) ($input['discount'] ?? 0));
$redeemedPoints = max(0, (int) ($input['redeemed_points'] ?? 0));
$method         = $input['payment_method'] ?? 'Cash';
$custId         = (int) ($input['customer_id'] ?? 0) ?: null;
$note           = mb_substr(trim($input['note'] ?? ''), 0, 255);

$validMethods = ['Cash', 'M-Pesa', 'Card', 'Bank Transfer'];
if (!in_array($method, $validMethods, true)) {
    json_out(['ok' => false, 'error' => 'Invalid payment method.'], 422);
}
if (!is_array($items) || !count($items)) {
    json_out(['ok' => false, 'error' => 'Cart is empty.'], 422);
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $subtotal = 0;
    $resolved = [];

    foreach ($items as $row) {
        $pid = (int) ($row['id'] ?? 0);
        $qty = (int) ($row['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            throw new RuntimeException('Invalid cart item.');
        }
        // Lock the product row for the stock check
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$pid]);
        $p = $stmt->fetch();
        if (!$p) {
            throw new RuntimeException('Product not found.');
        }
        if ($p['stock_qty'] < $qty) {
            throw new RuntimeException("Insufficient stock for {$p['name']} (only {$p['stock_qty']} left).");
        }
        $line = $qty * (float) $p['selling_price'];
        $subtotal += $line;
        $resolved[] = [
            'id'    => $pid,
            'name'  => $p['name'],
            'qty'   => $qty,
            'price' => (float) $p['selling_price'],
            'cost'  => (float) $p['purchase_price'],
            'line'  => $line,
        ];
    }

    // Deduct loyalty points and add to discount
    if ($custId && $redeemedPoints > 0) {
        $cStmt = $pdo->prepare('SELECT loyalty_points FROM customers WHERE id = ? FOR UPDATE');
        $cStmt->execute([$custId]);
        $cPoints = (int) $cStmt->fetchColumn();
        if ($cPoints < $redeemedPoints) {
            throw new RuntimeException('Customer has insufficient loyalty points.');
        }
        $pdo->prepare('UPDATE customers SET loyalty_points = loyalty_points - ? WHERE id = ?')
            ->execute([$redeemedPoints, $custId]);
        $discount += $redeemedPoints;
        $note = trim($note . " (Redeemed " . $redeemedPoints . " points)");
    }

    $discount = min($discount, $subtotal);
    $taxRate  = (float) (settings()['tax_rate'] ?? 0);
    $tax      = round(($subtotal - $discount) * $taxRate / 100, 2);
    $total    = round($subtotal - $discount + $tax, 2);

    $receiptNo = 'RCP-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    $saleStmt = $pdo->prepare(
        'INSERT INTO sales (receipt_no, user_id, customer_id, subtotal, discount, tax, total, paid, change_due, payment_method, note)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $saleStmt->execute([
        $receiptNo, current_user()['id'], $custId,
        $subtotal, $discount, $tax, $total, $total, 0, $method, $note,
    ]);
    $saleId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare(
        'INSERT INTO sale_items (sale_id, product_id, product_name, qty, price, cost, line_total)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stockStmt = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?');
    $moveStmt  = $pdo->prepare(
        'INSERT INTO stock_movements (product_id, type, qty, reason, user_id) VALUES (?,?,?,?,?)'
    );

    foreach ($resolved as $r) {
        $itemStmt->execute([$saleId, $r['id'], $r['name'], $r['qty'], $r['price'], $r['cost'], $r['line']]);
        $stockStmt->execute([$r['qty'], $r['id']]);
        $moveStmt->execute([$r['id'], 'out', $r['qty'], 'Sale ' . $receiptNo, current_user()['id']]);
    }

    // Loyalty: 1 point per 100 spent
    if ($custId) {
        $points = (int) floor($total / 100);
        if ($points > 0) {
            $pdo->prepare('UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?')
                ->execute([$points, $custId]);
        }
    }

    $pdo->commit();
    audit('sale', "Sale $receiptNo total " . number_format($total, 2));

    json_out(['ok' => true, 'receipt_no' => $receiptNo, 'total' => $total]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(['ok' => false, 'error' => $e->getMessage()], 400);
}
