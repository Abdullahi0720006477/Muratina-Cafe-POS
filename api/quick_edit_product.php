<?php
require_once __DIR__ . '/../includes/auth.php';

// Allow manager and cashier to perform quick edits
if (user_role() !== 'manager' && user_role() !== 'cashier') {
    http_response_code(403);
    die(json_encode(['ok' => false, 'error' => 'Unauthorized']));
}

header('Content-Type: application/json');

// CSRF check
$csrf = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_verify($csrf)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Invalid or expired security token. Please refresh.']);
    exit;
}

$action = $_POST['action'] ?? 'save';
$id = (int) ($_POST['id'] ?? 0);
$pdo = db();

if ($action === 'delete') {
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid product ID.']);
        exit;
    }

    $pStmt = $pdo->prepare('SELECT id, name FROM products WHERE id = ?');
    $pStmt->execute([$id]);
    $product = $pStmt->fetch();
    if (!$product) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Product not found.']);
        exit;
    }

    $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$id]);
    audit('product_deactivate', 'Deactivated product ' . $product['name'] . ' (ID: ' . $id . ') via POS');
    echo json_encode(['ok' => true, 'deleted' => true, 'id' => $id]);
    exit;
}

if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    $catId = (int) ($_POST['category_id'] ?? 0);
    $price = (float) ($_POST['selling_price'] ?? 0);
    $qty = (int) ($_POST['stock_qty'] ?? 100);

    if (empty($name) || $catId <= 0 || $price < 0 || $qty < 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Please fill in all required fields with valid values.']);
        exit;
    }

    // Check if category exists
    $cStmt = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
    $cStmt->execute([$catId]);
    if (!$cStmt->fetch()) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid category selected.']);
        exit;
    }

    // Generate standard SKU & barcode
    $sku = 'PROD-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    $barcode = '600' . str_pad(mt_rand(1, 9999999), 7, '0', STR_PAD_LEFT);

    // Save image if uploaded
    $imagePath = null;
    if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true)) {
            $fn = 'prod_new_' . time() . '_' . mt_rand(100, 999) . '.' . $ext;
            if (!is_dir(UPLOAD_DIR . '/products/')) {
                mkdir(UPLOAD_DIR . '/products/', 0777, true);
            }
            if (move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_DIR . '/products/' . $fn)) {
                $imagePath = 'uploads/products/' . $fn;
            }
        }
    }

    // Default supplier (first supplier in DB or NULL)
    $sup = $pdo->query('SELECT id FROM suppliers LIMIT 1')->fetch();
    $supId = $sup ? (int)$sup['id'] : null;

    $purchasePrice = $price * 0.6; // 40% margin default

    $pdo->prepare('
        INSERT INTO products (name, sku, barcode, category_id, supplier_id, purchase_price, selling_price, stock_qty, low_stock, image, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 5, ?, 1)
    ')->execute([$name, $sku, $barcode, $catId, $supId, $purchasePrice, $price, $qty, $imagePath]);

    $newId = (int) $pdo->lastInsertId();

    audit('product_create', 'Created product ' . $name . ' (ID: ' . $newId . ') via POS');

    echo json_encode([
        'ok' => true,
        'added' => true,
        'product' => [
            'id' => $newId,
            'name' => $name,
            'selling_price' => $price,
            'stock_qty' => $qty,
            'category_id' => $catId,
            'image' => $imagePath
        ]
    ]);
    exit;
}

// Default: Save/Edit product
$price = (float) ($_POST['selling_price'] ?? 0);

if ($id <= 0 || $price < 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid inputs.']);
    exit;
}

// Check if product exists
$pStmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$pStmt->execute([$id]);
$product = $pStmt->fetch();
if (!$product) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Product not found.']);
    exit;
}

$imagePath = $product['image'];
if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true)) {
        $fn = 'prod_' . $id . '_' . time() . '.' . $ext;
        if (!is_dir(UPLOAD_DIR . '/products/')) {
            mkdir(UPLOAD_DIR . '/products/', 0777, true);
        }
        if (move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_DIR . '/products/' . $fn)) {
            $imagePath = 'uploads/products/' . $fn;
        }
    }
}

$pdo->prepare('UPDATE products SET selling_price = ?, image = ? WHERE id = ?')
    ->execute([$price, $imagePath, $id]);

audit('product_update', 'Updated product ' . $product['name'] . ' (ID: ' . $id . ') via POS');

echo json_encode([
    'ok' => true,
    'id' => $id,
    'selling_price' => $price,
    'image' => $imagePath
]);
exit;
