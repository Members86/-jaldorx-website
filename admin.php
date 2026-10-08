<?php
declare(strict_types=1);
session_start();

$configFile = __DIR__ . '/api/config.php';
if (!is_file($configFile)) { http_response_code(500); exit('Konfiguration fehlt.'); }
require $configFile;
if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(500); exit('Datenbank nicht konfiguriert.'); }

$adminPassword = defined('JALDORX_ADMIN_PASSWORD') ? JALDORX_ADMIN_PASSWORD : '';
if ($adminPassword === '') { http_response_code(500); exit('Admin-Passwort fehlt in api/config.php.'); }

if (isset($_POST['logout'])) { $_SESSION = []; session_destroy(); header('Location: admin.php'); exit; }

if (!isset($_SESSION['jdx_admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (hash_equals($adminPassword, (string)$_POST['password'])) {
            $_SESSION['jdx_admin'] = true;
            header('Location: admin.php'); exit;
        }
        $loginError = 'Falsches Passwort.';
    }
    ?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JALDORX Admin</title>
    <style>body{margin:0;background:#050505;color:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;min-height:100vh;display:grid;place-items:center}.box{width:min(420px,calc(100% - 32px));padding:30px;border:1px solid #ffffff20;border-radius:18px;background:#0b0b0b}h1{margin-top:0}.logo{font-weight:900;font-size:24px;margin-bottom:25px}.logo b{color:#e5232e}input,button{width:100%;box-sizing:border-box;padding:14px;border-radius:10px;margin-top:10px;font-size:16px}input{background:#050505;color:#fff;border:1px solid #444}button{background:#fff;color:#000;border:0;font-weight:900}.err{color:#ff8b8b;margin-top:12px}</style></head><body><form class="box" method="post"><div class="logo">JALDOR<b>X</b> ADMIN</div><h1>Anmeldung</h1><input type="password" name="password" placeholder="Admin-Passwort" autocomplete="current-password" required><button type="submit">ANMELDEN</button><?php if(!empty($loginError)) echo '<div class="err">'.htmlspecialchars($loginError,ENT_QUOTES,'UTF-8').'</div>'; ?></form></body></html><?php exit;
}

$message=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['stock'])) {
    $stock=max(0,(int)$_POST['stock']);
    $stmt=$pdo->prepare("UPDATE inventory SET stock=? WHERE sku=?");
    $stmt->execute([$stock,'MYSTERY-DUFTBAUM']);
    $message='Lagerbestand gespeichert.';
}

$inventory=$pdo->query("SELECT stock,reserved FROM inventory WHERE sku='MYSTERY-DUFTBAUM' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$stock=(int)($inventory['stock']??0); $reserved=(int)($inventory['reserved']??0);
$orders=$pdo->query("SELECT * FROM orders ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JALDORX Admin</title>
<style>:root{--gold:#d7b43a;--red:#e5232e}*{box-sizing:border-box}body{margin:0;background:#050505;color:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif}.wrap{width:min(1100px,calc(100% - 28px));margin:auto}.top{display:flex;justify-content:space-between;align-items:center;padding:22px 0;border-bottom:1px solid #ffffff15}.logo{font-size:24px;font-weight:900}.logo b{color:var(--red)}button,.btn{border:0;border-radius:9px;padding:11px 15px;font-weight:900;cursor:pointer}.logout{background:#151515;color:#fff;border:1px solid #333}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin:28px 0}.card{background:#0b0b0b;border:1px solid #ffffff18;border-radius:16px;padding:24px}.big{font-size:48px;font-weight:900;color:var(--gold)}label{display:block;color:#aaa;margin-bottom:8px}input[type=number]{width:100%;padding:13px;background:#050505;color:#fff;border:1px solid #444;border-radius:9px;font-size:18px;margin-bottom:10px}.save{background:#fff;color:#000}.ok{color:#8cffad;margin-bottom:15px}.table{overflow:auto}table{width:100%;border-collapse:collapse;min-width:760px}th,td{text-align:left;padding:12px;border-bottom:1px solid #ffffff12;font-size:13px}th{color:#aaa}.status{color:var(--gold)}@media(max-width:760px){.grid{grid-template-columns:1fr}.big{font-size:40px}}</style></head><body><div class="wrap"><div class="top"><div class="logo">JALDOR<b>X</b> ADMIN</div><form method="post"><button class="logout" name="logout" value="1">ABMELDEN</button></form></div>
<div class="grid"><section class="card"><h2>MYSTERY DUFTBAUM</h2><div class="big"><?php echo $stock; ?></div><p>Stück auf Lager</p><p>Reserviert: <?php echo $reserved; ?></p><form method="post"><label>Bestand ändern</label><input type="number" name="stock" min="0" value="<?php echo $stock; ?>" required><button class="save" type="submit">BESTAND SPEICHERN</button></form></section>
<section class="card"><h2>Übersicht</h2><p>Bestellungen: <strong><?php echo count($orders); ?></strong></p><p>Verfügbar: <strong><?php echo max(0,$stock-$reserved); ?></strong></p><p>Max. pro Bestellung: <strong>40 Duftbäume</strong></p></section></div>
<?php if($message) echo '<div class="ok">'.h($message).'</div>'; ?>
<section class="card"><h2>Letzte Bestellungen</h2><div class="table"><?php if(!$orders): ?><p>Noch keine Bestellungen vorhanden.</p><?php else: ?><table><thead><tr><th>Bestellung</th><th>Kunde</th><th>Menge</th><th>Gesamt</th><th>Status</th></tr></thead><tbody><?php foreach($orders as $o): ?><tr><td><?php echo h($o['order_number']??$o['id']??''); ?></td><td><?php echo h(($o['customer_first_name']??'').' '.($o['customer_last_name']??'')); ?></td><td><?php echo h($o['quantity']??''); ?></td><td><?php echo h($o['grand_total']??''); ?> €</td><td class="status"><?php echo h($o['status']??'open'); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></section></div></body></html>