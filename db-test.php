<?php
// JALDORX – temporärer Datenbank-Verbindungstest
// NICHT im öffentlichen Webspace mit echten Zugangsdaten lassen.
// Nach erfolgreichem Test wieder löschen.

$dbHost = 'DB_HOST_EINTRAGEN';
$dbPort = 3306;
$dbName = 'DB_NAME_EINTRAGEN';
$dbUser = 'DB_USER_EINTRAGEN';
$dbPass = 'DB_PASSWORT_EINTRAGEN';

header('Content-Type: text/plain; charset=utf-8');

if ($dbHost === 'DB_HOST_EINTRAGEN') {
    exit("TESTDATEI BEREIT – noch keine Zugangsdaten eingetragen.\n");
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5
        ]
    );
    echo "DATENBANKVERBINDUNG OK\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "DATENBANKVERBINDUNG FEHLGESCHLAGEN\n";
    echo $e->getMessage();
}
