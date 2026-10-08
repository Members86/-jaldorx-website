<?php
declare(strict_types=1);

/*
 * JALDORX – serverseitige Bestellerfassung
 * Die Datei erwartet /api/config.php auf dem IONOS-Webspace.
 * config.php wird NICHT in GitHub gespeichert.
 */

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

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jaldorx_inventory (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sku VARCHAR(100) NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            stock INT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_jaldorx_inventory_sku (sku)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jaldorx_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_number VARCHAR(30) NOT NULL,
            customer_first_name VARCHAR(100) NOT NULL,
            customer_last_name VARCHAR(100) NOT NULL,
            street VARCHAR(255) NOT NULL,
            postal_code VARCHAR(20) NOT NULL,
            city VARCHAR(120) NOT NULL,
            country VARCHAR(80) NOT NULL DEFAULT 'Deutschland',
            email VARCHAR(255) NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            quantity INT NOT NULL,
            merchandise_total DECIMAL(10,2) NOT NULL,
            shipping_total DECIMAL(10,2) NOT NULL,
            grand_total DECIMAL(10,2) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_jaldorx_order_number (order_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jaldorx_order_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            item_type VARCHAR(20) NOT NULL,
            item_code VARCHAR(50) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            line_total DECIMAL(10,2) NOT NULL,
            tree_quantity INT NOT NULL,
            PRIMARY KEY (id),
            KEY idx_jaldorx_order_items_order_id (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        INSERT INTO jaldorx_inventory (sku, product_name, stock)
        VALUES ('mystery_duftbaum', 'MYSTERY DUFTBAUM', 0)
        ON DUPLICATE KEY UPDATE product_name = VALUES(product_name)
    ");

    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        throw new RuntimeException('INVALID_JSON');
    }

    $customer = $data['customer'] ?? [];
    $items = $data['items'] ?? [];

    $required = ['firstName','lastName','street','postalCode','city','email'];
    foreach ($required as $key) {
        if (!is_string($customer[$key] ?? null) || trim($customer[$key]) === '') {
            throw new RuntimeException('MISSING_CUSTOMER_DATA');
        }
    }

    if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('INVALID_EMAIL');
    }

    if (!is_array($items) || !$items) {
        throw new RuntimeException('EMPTY_CART');
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
        'two' => ['name' => '2 gute garantiert', 'price' => 43.90],
        'four' => ['name' => '4 gute garantiert', 'price' => 45.90],
        'six' => ['name' => '6 gute garantiert', 'price' => 47.90],
        'nine' => ['name' => '9 gute + 1 schlechter', 'price' => 49.90],
    ];

    $normalPrice = static function (int $qty) use ($tiers): float {
        if ($qty === 40) return 149.90;
        foreach ($tiers as $tier) {
            if ($qty <= $tier['max']) return $tier['price'];
        }
        throw new RuntimeException('INVALID_QUANTITY');
    };

    $clean = static function ($value, int $max = 255): string {
        $value = trim((string)$value);
        return mb_substr($value, 0, $max);
    };

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
                'code' => 'mystery_duftbaum',
                'name' => 'MYSTERY DUFTBAUM',
                'quantity' => $qty,
                'unit_price' => $qty === 40 ? 149.90 / 40 : $unit,
                'line_total' => $line,
                'tree_quantity' => $qty
            ];
            $treeQty += $qty;
            $merchandiseTotal += $line;
        } elseif (($item['mode'] ?? '') === 'box' && isset($boxes[$item['boxType']])) {
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

    if ($treeQty < 1 || $treeQty > $MAX_TREES) {
        throw new RuntimeException('MAX_40_TREES');
    }

    $shipping = $merchandiseTotal >= 99.00 ? 0.00 : ($treeQty <= 10 ? 3.49 : 5.99);
    $grandTotal = $merchandiseTotal + $shipping;

    $pdo->beginTransaction();

    $stockStmt = $pdo->query("SELECT stock FROM jaldorx_inventory WHERE sku = 'mystery_duftbaum' FOR UPDATE");
    $stock = (int)$stockStmt->fetchColumn();

    if ($stock < $treeQty) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'INSUFFICIENT_STOCK', 'available' => $stock]);
        exit;
    }

    $orderNumber = 'JX-' . random_int(100000, 999999);

    $stmt = $pdo->prepare("
        INSERT INTO jaldorx_orders
        (order_number, customer_first_name, customer_last_name, street, postal_code, city, country, email, payment_method, quantity, merchandise_total, shipping_total, grand_total)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $orderNumber,
        $clean($customer['firstName'], 100),
        $clean($customer['lastName'], 100),
        $clean($customer['street'], 255),
        $clean($customer['postalCode'], 20),
        $clean($customer['city'], 120),
        'Deutschland',
        $clean($customer['email'], 255),
        $clean($data['paymentMethod'] ?? 'test', 50),
        $treeQty,
        number_format($merchandiseTotal, 2, '.', ''),
        number_format($shipping, 2, '.', ''),
        number_format($grandTotal, 2, '.', '')
    ]);

    $orderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO jaldorx_order_items
        (order_id, item_type, item_code, item_name, quantity, unit_price, line_total, tree_quantity)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($normalized as $line) {
        $itemStmt->execute([
            $orderId,
            $line['type'],
            $line['code'],
            $line['name'],
            $line['quantity'],
            number_format($line['unit_price'], 2, '.', ''),
            number_format($line['line_total'], 2, '.', ''),
            $line['tree_quantity']
        ]);
    }

    $updateStock = $pdo->prepare("
        UPDATE jaldorx_inventory
        SET stock = stock - ?
        WHERE sku = 'mystery_duftbaum' AND stock >= ?
    ");
    $updateStock->execute([$treeQty, $treeQty]);

    if ($updateStock->rowCount() !== 1) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'STOCK_CHANGED']);
        exit;
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'orderNumber' => $orderNumber,
        'quantity' => $treeQty,
        'total' => round($grandTotal, 2)
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'ORDER_SAVE_FAILED']);
}
