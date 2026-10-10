-- JALDORX PayPal-Sandbox-Migration
-- Vor Ausführung Datenbank sichern. Einmalig in phpMyAdmin ausführen.
-- Diese Migration nur auf der Test-/Sandbox-Datenbank ausführen, bevor die API-Dateien aktiviert werden.
ALTER TABLE orders
  ADD COLUMN payment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
  ADD COLUMN paypal_order_id VARCHAR(100) NULL,
  ADD COLUMN reservation_expires_at DATETIME NULL,
  ADD COLUMN paypal_capture_id VARCHAR(100) NULL;

-- Keine zusätzlichen Indizes nötig, um den ersten Sandbox-Test durchzuführen.
-- Die Lagerreservierung wird in inventory.reserved verwaltet.
-- NICHT live schalten, bevor Checkout, Capture und Freigabe abgelaufener Reservierungen geprüft wurden.
