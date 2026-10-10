-- JALDORX PayPal-Sandbox-Vorbereitung
-- Vor Ausführung Datenbank sichern. Nicht automatisch ausgeführt.
-- MariaDB: neue Felder für Zahlungsstatus, PayPal-Referenz und Ablauf der Reservierung.

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS payment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS paypal_order_id VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS reservation_expires_at DATETIME NULL;

CREATE INDEX IF NOT EXISTS idx_orders_paypal_order_id ON orders (paypal_order_id);
CREATE INDEX IF NOT EXISTS idx_orders_payment_status ON orders (payment_status);

-- WICHTIG:
-- Diese Migration allein ändert keine Lagerbestände und bucht keine Bestellungen um.
-- Die Zahlungs-API muss Reservierungen transaktionssicher verwalten und PayPal-Status
-- serverseitig verifizieren, bevor sie payment_status auf 'paid' setzt.
