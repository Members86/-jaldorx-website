<?php
// JALDORX – zentrale Datenbankverbindung
// Keine Zugangsdaten direkt in diese Datei eintragen.

$configFile = __DIR__ . '/db-config.php';

if (!is_file($configFile)) {
    http_response_code(500);
    exit('Datenbank-Konfiguration fehlt.');
}

$config = require $configFile;

if (!is_array($config) || empty($config['host']) || empty($config['name']) || empty($config['user']) || !isset($config['pass'])) {
    http_response_code(500);
    exit('Datenbank-Konfiguration ist unvollständig.');
}

try {
    $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';port=' . ($config['port'] ?? 3306) . ';dbname=' . $config['name'] . ';charset=utf8mb4',
        $config['user'],
        $config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    exit('Datenbankverbindung fehlgeschlagen.');
}
