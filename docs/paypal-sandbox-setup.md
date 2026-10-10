# JALDORX PayPal Sandbox – Einrichtung und Test

**Dieser Zweig ist nicht live. Nicht nach `main` mergen, bevor alle Schritte bestanden sind.**

## 1. Voraussetzungen
- PayPal Developer Sandbox-App mit Sandbox Client ID und Secret.
- Die Datenbankmigration `docs/paypal-sandbox-db-migration.sql` einmalig auf der Shop-Datenbank ausführen.
- `inventory.reserved` muss vorhanden sein; der aktuelle Shop nutzt dieses Feld bereits.
- PHP-cURL muss auf IONOS aktiviert sein.

## 2. PayPal-Konfiguration auf IONOS
1. Im IONOS-Webspace-Dateimanager `/public/api` öffnen.
2. `paypal-config.example.php` als Vorlage verwenden und eine neue Datei `paypal-config.php` erstellen.
3. Nur in der IONOS-Datei die Sandbox Client ID und das Sandbox Secret eintragen. Niemals echte Zugangsdaten in GitHub, Screenshots oder Chat veröffentlichen.
4. Die Datei muss mit `<?php` beginnen und ein PHP-Array zurückgeben. `mode` muss `sandbox` sein.
5. `api/.htaccess` sperrt direkten HTTP-Zugriff auf die private Konfiguration und den PHP-Helper. Nach dem Upload testen, dass `https://jaldorx.de/api/paypal-config.php` eine 403-Antwort liefert.
6. `https://jaldorx.de/api/paypal-public-config.php` soll ohne Secrets nur `ok`, `mode` und die Client ID zurückgeben. Solange die Dateien noch nicht auf IONOS liegen, ist eine 503-Antwort erwartet.

## 3. Testzweig-Dateien
Dieser Zweig enthält:
- PayPal REST-Helper
- öffentliche Sandbox-Client-ID-Antwort für das JavaScript-SDK
- Create-Order-Endpoint mit Lagerreservierung
- Capture-Endpoint, der den PayPal-Status und EUR-Betrag serverseitig prüft
- Checkout mit PayPal-Buttons
- Datenbankmigration

## 4. Unbedingt vor Merge testen
1. Erst die Migration auf einer Sicherung/Testkopie der Datenbank ausführen.
2. Mit einem Sandbox-Käuferkonto einen Warenkorb mit kleinem Wert bestellen.
3. Vor Zahlung muss `stock` unverändert und `reserved` um die Warenmenge erhöht sein.
4. Bei abgebrochener Zahlung darf `stock` unverändert bleiben. Reservierungen laufen nach 30 Minuten ab; die aktuelle Implementierung gibt sie bei der nächsten PayPal-Bestellinitialisierung frei.
5. Nach erfolgreicher Sandbox-Zahlung muss der Endpoint nur bei serverseitig bestätigtem PayPal-Capture `payment_status=paid` setzen, `stock` reduzieren und `reserved` reduzieren.
6. Wiederholte Capture-Aufrufe dürfen den Bestand nicht ein zweites Mal reduzieren.
7. Tests für Einzelprodukte, Sets, Versandkosten, kostenloser Versand ab 99 €, 40-Stück-Limit und unzureichenden Lagerbestand durchführen.
8. Prüfen, dass die bestehenden Warenkorb-, Shop- und mobilen Layouts unverändert funktionieren.

## 5. Grenzen dieser ersten Sandbox-Version
- Abgelaufene Reservierungen werden bei der nächsten PayPal-Bestellinitialisierung freigegeben; für automatische zeitnahe Freigabe ohne neue Bestellungen wäre ein Cronjob nötig.
- Das ist noch keine Live-Zahlungseinrichtung. Vor Livebetrieb müssen Business-Konto, Live-Zugangsdaten, Fehler-/Webhook-Behandlung und rechtliche Texte geprüft werden.
