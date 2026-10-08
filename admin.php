<?php
session_start();

require __DIR__ . '/api/config.php';

if (!isset($pdo)) {
    exit('Datenbankverbindung fehlt.');
}

if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

if (!isset($_SESSION['jaldorx_admin'])) {
    if (isset($_POST['password'])) {
        if (hash_equals(JALDORX_ADMIN_PASSWORD, (string)$_POST['password'])) {
            $_SESSION['jaldorx_admin'] = true;
            header('Location: admin.php');
            exit;
        }
        $error = 'Falsches Passwort.';
    }

    echo '<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>JALDORX Admin</title><style>body{margin:0;background:#050505;color:white;font-family:Arial;display:grid;place-items:center;min-height:100vh}.box{width:min(420px,calc(100% - 40px));padding:30px;background:#0b0b0b;border:1px solid #333;border-radius:18px}input,button{width:100%;padding:15px;margin-top:12px;box-sizing:border-box;border-radius:10px;font-size:16px}input{background:#050505;color:white;border:1px solid #555}button{background:white;color:black;border:0;font-weight:bold}.red{color:#ff7777;margin-top:12px}</style><form class="box" method="post"><h1>JALDORX <span style="color:#e5232e">X</span> ADMIN</h1><h2>Anmeldung</h2><input type="password" name="password" placeholder="Admin-Passwort" required><button>ANMELDEN</button>'.(!empty($error)?'<div class="red">'.htmlspecialchars($error).'</div>':'').'</form></html>';
    exit;
}

try {
    if (isset($_POST['stock'])) {
        $stock = max(0, (int)$_POST['stock']);
        $stmt = $pdo->prepare('UPDATE inventory SET stock = ? LIMIT 1');
        $stmt->execute([$stock]);
        $saved = true;
    }

    $row = $pdo->query('SELECT stock FROM inventory LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $stock = $row ? (int)$row['stock'] : 0;
} catch (Throwable $e) {
    http_response_code(500);
    exit('Admin-Datenbankfehler: ' . htmlspecialchars($e->getMessage()));
}

?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>JALDORX Admin</title>
<style>
body{margin:0;background:#050505;color:#fff;font-family:Arial,sans-serif}
.wrap{width:min(700px,calc(100% - 30px));margin:auto}
.top{display:flex;justify-content:space-between;align-items:center;padding:22px 0;border-bottom:1px solid #333}
.logo{font-size:24px;font-weight:900}.logo span{color:#e5232e}
.card{margin-top:28px;background:#0b0b0b;border:1px solid #333;border-radius:18px;padding:25px}
.stock{font-size:56px;font-weight:900;color:#d7b43a}
input{width:100%;box-sizing:border-box;padding:14px;background:#050505;color:white;border:1px solid #555;border-radius:9px;font-size:18px;margin:10px 0}
button{padding:12px 18px;border:0;border-radius:9px;font-weight:bold}
.save{background:white;color:black}.logout{background:#151515;color:white;border:1px solid #444}
.ok{color:#7dff9b;margin-top:15px}
</style>
</head>
<body>
<div class="wrap">
<div class="top"><div class="logo">JALDOR<span>X</span> ADMIN</div><form method="post"><button class="logout" name="logout" value="1">ABMELDEN</button></form></div>
<div class="card">
<h1>Lagerbestand</h1>
<h2>MYSTERY DUFTBAUM</h2>
<div class="stock"><?php echo $stock; ?></div>
<p>Stück auf Lager</p>
<form method="post">
<label>Neuen Bestand eingeben</label>
<input type="number" name="stock" min="0" value="<?php echo $stock; ?>" required>
<button class="save" type="submit">BESTAND SPEICHERN</button>
</form>
<?php if (!empty($saved)) echo '<div class="ok">Lagerbestand gespeichert.</div>'; ?>
</div>
</div>
</body>
</html>