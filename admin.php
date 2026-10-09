<?php
session_start();
require __DIR__ . '/api/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    exit('Datenbankverbindung fehlt.');
}

if (isset($_POST['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (!isset($_SESSION['jaldorx_admin'])) {
    if (isset($_POST['password'])) {
        if (defined('JALDORX_ADMIN_PASSWORD') && hash_equals(JALDORX_ADMIN_PASSWORD, (string)$_POST['password'])) {
            $_SESSION['jaldorx_admin'] = true;
            header('Location: admin.php');
            exit;
        }
        $error = 'Falsches Passwort.';
    }

    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JALDORX Admin</title><style>body{margin:0;background:#050505;color:white;font-family:Arial;display:grid;place-items:center;min-height:100vh}.box{width:min(420px,calc(100% - 40px));padding:30px;background:#0b0b0b;border:1px solid #333;border-radius:18px}input,button{width:100%;padding:15px;margin-top:12px;box-sizing:border-box;border-radius:10px;font-size:16px}input{background:#050505;color:white;border:1px solid #555}button{background:white;color:black;border:0;font-weight:bold}.red{color:#ff7777;margin-top:12px}</style></head><body><form class="box" method="post"><h1>JALDOR<span style="color:#e5232e">X</span> ADMIN</h1><h2>Anmeldung</h2><input type="password" name="password" placeholder="Admin-Passwort" required><button>ANMELDEN</button>';
    if (!empty($error)) echo '<div class="red">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '</form></body></html>';
    exit;
}

function jdx_h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function jdx_pick(array $row, array $keys, string $fallback = '—'): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return (string)$row[$key];
        }
    }
    return $fallback;
}

$message = '';
$errorMessage = '';
try {
    if (isset($_POST['stock'])) {
        $newStock = max(0, (int)$_POST['stock']);
        $inventoryColumns = $pdo->query('SHOW COLUMNS FROM inventory')->fetchAll(PDO::FETCH_ASSOC);
        $hasSku = false;
        foreach ($inventoryColumns as $column) {
            if (($column['Field'] ?? '') === 'sku') $hasSku = true;
        }
        if ($hasSku) {
            $stmt = $pdo->prepare('UPDATE inventory SET stock = ? WHERE sku = ?');
            $stmt->execute([$newStock, 'MYSTERY-DUFTBAUM']);
        } else {
            $stmt = $pdo->prepare('UPDATE inventory SET stock = ? LIMIT 1');
            $stmt->execute([$newStock]);
        }
        $message = 'Lagerbestand gespeichert.';
    }

    // Die vorhandene inventory-Tabelle hat offenbar keine sku-Spalte.
    // Daher den vorhandenen Lagerbestand ohne SKU-Filter laden.
    $inventoryRow = $pdo->query('SELECT * FROM inventory LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $stock = $inventoryRow ? (int)($inventoryRow['stock'] ?? 0) : 0;
    $reserved = $inventoryRow ? (int)($inventoryRow['reserved'] ?? 0) : 0;

    $orders = [];
    $ordersColumns = $pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_ASSOC);
    $orderColumnNames = array_map(static fn($c) => $c['Field'] ?? '', $ordersColumns);
    $sortColumn = in_array('id', $orderColumnNames, true) ? 'id' : ($orderColumnNames[0] ?? '');
    if ($sortColumn !== '') {
        $orders = $pdo->query('SELECT * FROM orders ORDER BY ' . preg_replace('/[^a-zA-Z0-9_]/', '', $sortColumn) . ' DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    }
    $orderItems = [];
    $itemsColumns = $pdo->query('SHOW COLUMNS FROM order_items')->fetchAll(PDO::FETCH_ASSOC);
    $itemColumnNames = array_map(static fn($c) => $c['Field'] ?? '', $itemsColumns);
    if (in_array('order_id', $itemColumnNames, true)) {
        $orderItems = $pdo->query('SELECT * FROM order_items ORDER BY order_id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
    $orders = $orders ?? [];
    $orderItems = $orderItems ?? [];
    $stock = $stock ?? 0;
    $reserved = $reserved ?? 0;
}

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>JALDORX Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#050505;color:#fff;font-family:Arial,sans-serif}.wrap{width:min(1100px,calc(100% - 28px));margin:auto}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:22px 0;border-bottom:1px solid #333}.logo{font-size:24px;font-weight:900}.logo span{color:#e5232e}.card{margin-top:24px;background:#0b0b0b;border:1px solid #333;border-radius:16px;padding:22px}.stock{font-size:48px;font-weight:900;color:#d7b43a}.stockform{max-width:420px}input{width:100%;padding:13px;background:#050505;color:#fff;border:1px solid #555;border-radius:9px;font-size:18px;margin:10px 0}button{padding:12px 16px;border:0;border-radius:9px;font-weight:bold;cursor:pointer}.save{background:#fff;color:#000}.logout{background:#151515;color:#fff;border:1px solid #444}.ok{color:#7dff9b;margin-top:14px}.err{color:#ff9999;overflow-wrap:anywhere}.tablewrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:720px}th,td{text-align:left;padding:12px 10px;border-bottom:1px solid #292929;vertical-align:top}th{color:#d7b43a;font-size:13px;text-transform:uppercase}td{font-size:14px}.muted{color:#aaa}.items{font-size:12px;color:#bbb;margin-top:5px;max-width:260px}@media(max-width:600px){.card{padding:16px}.stock{font-size:40px}.logo{font-size:20px}}
</style>
</head>
<body>
<div class="wrap">
<div class="top"><div class="logo">JALDOR<span>X</span> ADMIN</div><form method="post"><button class="logout" name="logout" value="1">ABMELDEN</button></form></div>

<section class="card">
<h1>Lagerbestand</h1>
<h2>MYSTERY DUFTBAUM</h2>
<div class="stock"><?php echo $stock; ?></div>
<p>Stück auf Lager · Reserviert: <strong><?php echo $reserved; ?></strong> · Verfügbar: <strong><?php echo max(0, $stock - $reserved); ?></strong></p>
<form class="stockform" method="post">
<label for="stock">Neuen Bestand eingeben</label>
<input id="stock" type="number" name="stock" min="0" value="<?php echo $stock; ?>" required>
<button class="save" type="submit">BESTAND SPEICHERN</button>
</form>
<?php if ($message !== '') echo '<div class="ok">' . jdx_h($message) . '</div>'; ?>
<?php if ($errorMessage !== '') echo '<p class="err">Hinweis beim Laden der Bestellungen: ' . jdx_h($errorMessage) . '</p>'; ?>
</section>

<section class="card">
<h1>Bestellungen</h1>
<p class="muted">Die letzten 50 Bestellungen. Bestellungen erscheinen hier, sobald der Checkout sie erfolgreich in der Datenbank speichert.</p>
<?php if (!$orders): ?>
<p>Noch keine Bestellungen vorhanden.</p>
<?php else: ?>
<div class="tablewrap"><table>
<thead><tr><th>Bestellung</th><th>Kunde</th><th>E-Mail</th><th>Stück</th><th>Gesamt</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($orders as $order): ?>
<tr>
<td><strong><?php echo jdx_h(jdx_pick($order, ['order_number','order_no','number','id'])); ?></strong><div class="muted"><?php echo jdx_h(jdx_pick($order, ['created_at','created','order_date','date'], '')); ?></div>
<?php
$orderId = $order['id'] ?? null;
if ($orderId !== null && $orderItems) {
    $shown = 0;
    echo '<div class="items">';
    foreach ($orderItems as $oi) {
        if ((string)($oi['order_id'] ?? '') === (string)$orderId && $shown < 8) {
            echo jdx_h(jdx_pick($oi, ['item_name','name','item_code'], 'Artikel')) . ' × ' . jdx_h(jdx_pick($oi, ['quantity'], '1')) . '<br>';
            $shown++;
        }
    }
    echo '</div>';
}
?>
</td>
<td><?php echo jdx_h(trim(jdx_pick($order, ['customer_first_name','first_name','firstname'], '') . ' ' . jdx_pick($order, ['customer_last_name','last_name','lastname'], '')) ?: jdx_pick($order, ['customer_name','name'])); ?><div class="muted"><?php echo jdx_h(jdx_pick($order, ['street','address'], '')); ?><br><?php echo jdx_h(trim(jdx_pick($order, ['postal_code','postcode','zip'], '') . ' ' . jdx_pick($order, ['city','town'], ''))); ?></div></td>
<td><?php echo jdx_h(jdx_pick($order, ['email','customer_email'])); ?></td>
<td><?php echo jdx_h(jdx_pick($order, ['quantity','total_quantity','items_count'])); ?></td>
<td><strong><?php echo jdx_h(jdx_pick($order, ['grand_total','total','total_amount'])); ?> €</strong></td>
<td><?php echo jdx_h(jdx_pick($order, ['status','order_status'], 'offen')); ?><div class="muted"><?php echo jdx_h(jdx_pick($order, ['payment_method'], '')); ?></div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</section>
</div>
</body>
</html>