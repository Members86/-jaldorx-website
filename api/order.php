<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_CONFIG_MISSING']);
    exit;
}

require $configFile;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DATABASE_NOT_CONFIGURED']);
    exit;
}

$MAX_TREES = 40;

$tiers = [
    ['max' => 4, 'price' => 4.49],
    ['max' => 9, 'price' => 4.29],
    ['max' => 19, 'price' => 4.19],
    ['max' => 29, 'price' => 4.09],
    ['max' => 39, 'price' => 3.85],
];

$boxes = [
    'two'   => ['name' => '2 gute garantiert', 'price' => 43.90],
    'four'  => ['name' => '4 gute garantiert', 'price' => 45.90],
    'six'   => ['name' => '6 gute garantiert', 'price' => 47.90],
    'nine'  => ['name' => '9 gute + 1 schlechter', 'price' => 49.90],
];

$normalPrice = static function (int $qty) use ($tiers): float {
    if ($qty === 40) return 149.90;
    foreach ($tiers as $tier) {
        if ($qty <= $tier['max']) return $tier['price'];
    }
    throw new RuntimeException('INVALID_QUANTITY');
};

$clean = static function ($value, int $max = 255): string {
    return mb_substr(trim((string)$value), 0, $max);
};

$tableColumns = static function (PDO $pdo, string $table): array {
    $allowed = ['products', 'inventory', 'orders', 'order_items'];
    if (!in_array($table, $allowed, true)) throw new RuntimeException('INVALID_TABLE');
    $stmt = $pdo->query("SHOW COLUMNS FROM $table");
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['Field'])) $columns[] = $row['Field'];
    }
    return $columns;
};

$insertExisting = static function (PDO $pdo, string $table, array $values, array $columns): int {
    $data = [];
    foreach ($values as $column => $value) {
        if (in_array($column, $columns, true)) $data[$column] = $value;
    }
    if (!$data) throw new RuntimeException('NO_MATCHING_COLUMNS');

    $names = array_keys($data);
    $marks = implode(',', array_fill(0, count($names), '?'));
    $sql = "INSERT INTO $table (" . implode(',', $names) . ") VALUES ($marks)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
    return (int)$pdo->lastInsertId();
};

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) throw new RuntimeException('INVALID_JSON');

    $customer = $data['customer'] ?? [];
    $items = $data['items'] ?? [];

    foreach (['firstName','lastName','street','postalCode','city','email'] as $key) {
        if (!is_string($customer[$key] ?? null) || trim($customer[$key]) === '') {
            throw new RuntimeException('MISSING_CUSTOMER_DATA');
        }
    }

    if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('INVALID_EMAIL');
    }

    if (!is_array($items) || !$items) throw new RuntimeException('EMPTY_CART');

    $normalized = [];
    $treeQty = 0;
    $merchandiseTotal = 0.0;

    foreach ($items as $item) {
        if (!is_array($item)) continue;

        if (($item['mode'] ?? '') === 'normal') {
            $qty = (int)($item['qty'] ?? 0);
            if ($qty < 1 || $qty > $MAX_TREES) throw new RuntimeException('INVALID_QUANTITY');

            $unit = $normalPrice($qty);
            $line = $qty === 40 ? 149.90 : $qty * $unit;

            $normalized[] = [
                'type' => 'normal',
                'code' => 'MYSTERY-DUFTBAUM',
                'name' => 'MYSTERY DUFTBAUM',
                'quantity' => $qty,
                'unit_price' => $qty === 40 ? 149.90 / 40 : $unit,
                'line_total' => $line,
                'tree_quantity' => $qty
            ];

            $treeQty += $qty;
            $merchandiseTotal += $line;
        }

        if (($item['mode'] ?? '') === 'box' && isset($boxes[$item['boxType']])) {
            $boxQty = (int)($item['boxQty'] ?? 0);
            if ($boxQty < 1 || $boxQty > 4) throw new RuntimeException('INVALID_SET_QUANTITY');

            $box = $boxes[$item['boxType']];
            $line = $box['price'] * $boxQty;
            $trees = $boxQty * 10;

            $normalized[] = [
                'type' => 'box',
                'code' => 'set_' . $item['boxType'],
                'name' => 'Mystery 10er-Set · ' . $box['name'],
                'quantity' => $boxQty,
                'unit_price' => $box['price'],
                'line_total' => $line,
                'tree_quantity' => $trees
            ];

            $treeQty += $trees;
            $merchandiseTotal += $line;
        }
    }

    if ($treeQty < 1 || $treeQty > $MAX_TREES) throw new RuntimeException('MAX_40_TREES');

    $shipping = $merchandiseTotal >= 99.00 ? 0.00 : ($treeQty <= 10 ? 3.49 : 5.99);
    $grandTotal = $merchandiseTotal + $shipping;

    $productsColumns = $tableColumns($pdo, 'products');
    $inventoryColumns = $tableColumns($pdo, 'inventory');
    $ordersColumns = $tableColumns($pdo, 'orders');
    $itemsColumns = $tableColumns($pdo, 'order_items');

    $product = $pdo->query("SELECT * FROM products WHERE sku = 'MYSTERY-DUFTBAUM' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$product) throw new RuntimeException('PRODUCT_NOT_FOUND');

    $productId = isset($product['id']) ? (int)$product['id'] : null;

    $pdo->beginTransaction();

    if (in_array('sku', $inventoryColumns, true)) {
        $stockStmt = $pdo->prepare("SELECT * FROM inventory WHERE sku = ? LIMIT 1 FOR UPDATE");
        $stockStmt->execute(['MYSTERY-DUFTBAUM']);
    } elseif ($productId !== null && in_array('product_id', $inventoryColumns, true)) {
        $stockStmt = $pdo->prepare("SELECT * FROM inventory WHERE product_id = ? LIMIT 1 FOR UPDATE");
        $stockStmt->execute([$productId]);
    } else {
        $stockStmt = $pdo->query("SELECT * FROM inventory ORDER BY id ASC LIMIT 1 FOR UPDATE");
    }

    $inventory = $stockStmt->fetch(PDO::FETCH_ASSOC);
    if (!$inventory || !array_key_exists('stock', $inventory)) throw new RuntimeException('INVENTORY_NOT_FOUND');

    $stock = (int)$inventory['stock'];

    if ($stock < $treeQty) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'INSUFFICIENT_STOCK', 'available' => $stock]);
        exit;
    }

    $orderNumber = 'JX-' . random_int(100000, 999999);

    $orderData = [
        'order_number' => $orderNumber,
        'customer_name' => $clean($customer['firstName'], 100) . ' ' . $clean($customer['lastName'], 100),
        'street' => $clean($customer['street'], 255),
        'postal_code' => $clean($customer['postalCode'], 20),
        'city' => $clean($customer['city'], 120),
        'country' => 'Deutschland',
        'customer_email' => $clean($customer['email'], 255),
        'payment_method' => $clean($data['paymentMethod'] ?? 'test', 50),
        'quantity' => $treeQty,
        'subtotal' => number_format($merchandiseTotal, 2, '.', ''),
        'shipping' => number_format($shipping, 2, '.', ''),
        'total' => number_format($grandTotal, 2, '.', ''),
        'status' => 'open'
    ];

    $orderId = $insertExisting($pdo, 'orders', $orderData, $ordersColumns);

    // Ensure monetary totals are written explicitly, even if the table has defaults/triggers.
    $totalsUpdate = $pdo->prepare("UPDATE orders SET subtotal = ?, shipping = ?, total = ? WHERE id = ?");
    $totalsUpdate->execute([
        number_format($merchandiseTotal, 2, '.', ''),
        number_format($shipping, 2, '.', ''),
        number_format($grandTotal, 2, '.', ''),
        $orderId
    ]);

    foreach ($normalized as $line) {
        $itemData = [
            'order_id' => $orderId,
            'product_id' => $productId,
            'item_type' => $line['type'],
            'sku' => $line['code'],
            'product_name' => $line['name'],
            'variant' => $line['type'],
            'quantity' => $line['quantity'],
            'unit_price' => number_format($line['unit_price'], 2, '.', ''),
            'line_total' => number_format($line['line_total'], 2, '.', ''),
            'tree_quantity' => $line['tree_quantity']
        ];
        $insertExisting($pdo, 'order_items', $itemData, $itemsColumns);
    }

    if (in_array('sku', $inventoryColumns, true)) {
        $update = $pdo->prepare("UPDATE inventory SET stock = stock - ? WHERE sku = ? AND stock >= ?");
        $update->execute([$treeQty, 'MYSTERY-DUFTBAUM', $treeQty]);
    } elseif ($productId !== null && in_array('product_id', $inventoryColumns, true)) {
        $update = $pdo->prepare("UPDATE inventory SET stock = stock - ? WHERE product_id = ? AND stock >= ?");
        $update->execute([$treeQty, $productId, $treeQty]);
    } else {
        $inventoryId = (int)($inventory['id'] ?? 0);
        $update = $pdo->prepare("UPDATE inventory SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $update->execute([$treeQty, $inventoryId, $treeQty]);
    }

    if ($update->rowCount() !== 1) throw new RuntimeException('STOCK_CHANGED');

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'orderNumber' => $orderNumber,
        'quantity' => $treeQty,
        'total' => round($grandTotal, 2)
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $status = $e->getMessage() === 'STOCK_CHANGED' ? 409 : 500;
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => 'ORDER_SAVE_FAILED']);
}
