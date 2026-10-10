-- JALDORX PayPal-Sandbox-Migration
-- Vor Ausführung Datenbank sichern. Einmalig in phpMyAdmin ausführen.
-- Bestehende Test-/Altbestellungen bleiben als legacy markiert.
ALTER TABLE orders
  ADD COLUMN payment_status VARCHAR(30) NOT NULL DEFAULT 'legacy',
  ADD COLUMN paypal_order_id VARCHAR(100) NULL,
  ADD COLUMN reservation_expires_at DATETIME NULL,
  ADD COLUMN paypal_capture_id VARCHAR(100) NULL;

-- Die neuen PayPal-Bestellungen müssen payment_status='pending' explizit setzen.
-- Die Lagerreservierung wird in inventory.reserved verwaltet.
-- NICHT live schalten, bevor Checkout, Capture und Reservierungsfreigabe geprüft wurden.
