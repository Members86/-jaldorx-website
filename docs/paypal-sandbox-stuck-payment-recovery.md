# PayPal-Sandbox: Umgang mit festhängenden Zahlungen

Dieses Dokument gilt nur für den isolierten Branch `paypal-sandbox-integration`. Es ist **keine Freigabe für Live-Zahlungen**.

## Grundregel

Wenn die Checkout-Seite `PAYMENT_RECEIVED_RECONCILIATION_REQUIRED` meldet, hat PayPal eine Zahlung bestätigt, aber die lokale Datenbank hat die Bestellung noch nicht sicher abgeschlossen. Der Käufer darf nicht erneut bezahlen. Die Bestellung bleibt zunächst in `capturing`; ihre Reservierung darf nicht automatisch freigegeben werden.

## Sicherer Prüfablauf

1. In PayPal Developer unter **Sandbox** die Bestellung/Zahlung anhand der PayPal-Order-ID prüfen. Nur ein eindeutig abgeschlossener Capture zählt als bezahlt.
2. In phpMyAdmin die lokale Bestellung nur lesend prüfen:

```sql
SELECT id, order_number, total, payment_status, status,
       paypal_order_id, paypal_capture_id, reservation_expires_at
FROM orders
WHERE payment_status = 'capturing'
ORDER BY id DESC;
```

3. Die zugehörige Menge und die Inventarwerte ebenfalls nur lesend kontrollieren:

```sql
SELECT order_id, SUM(tree_quantity) AS trees
FROM order_items
WHERE order_id = <LOKALE_BESTELLUNGS_ID>
GROUP BY order_id;

SELECT * FROM inventory;
```

4. Prüfen, ob PayPal-Betrag und Währung (EUR), Capture-Status, lokale Bestellnummer und Menge exakt zusammenpassen.
5. Bei Abweichungen oder fehlender Reservierung **keine manuellen UPDATE-/DELETE-Befehle ausführen** und keine Reservierung pauschal freigeben. Bestellung, PayPal-Order-ID, Capture-ID und Inventarwerte sichern und gezielt technisch abgleichen lassen.
6. Wenn PayPal nicht eindeutig abgeschlossen ist, nicht als bezahlt markieren und den Käufer nicht zu einer zweiten Zahlung auffordern, solange der Status ungeklärt ist.

## Warum keine automatische Korrektur per SQL?

Ein blindes Abbuchen oder Freigeben kann Bestand doppelt reduzieren oder die Reservierungen anderer Bestellungen beschädigen. Eine bestätigte Zahlung und ein lokaler Lagerfehler müssen anhand der konkreten Bestellung abgeglichen werden.

## Vor jeder Freigabe erforderlich

- Echter erfolgreicher PayPal-Sandbox-Kauf auf IONOS
- Abbruch und erneuter Versuch
- Doppelte/gleichzeitige Capture-Anfrage
- Ablauf einer Reservierung
- Prüfung der Bestandsänderung vor und nach jedem Szenario
- Prüfung des Admin-Bestellstatus

Bis dahin weder mergen noch live schalten.
