<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// PayPal checkout uses paypal-create-order.php and paypal-capture-order.php.
// This legacy endpoint used to deduct stock before payment. Keep it disabled
// on the sandbox integration branch to prevent accidental stock changes.
http_response_code(410);
echo json_encode([
    'ok' => false,
    'error' => 'LEGACY_CHECKOUT_DISABLED',
    'message' => 'Bitte den PayPal-Checkout verwenden.'
]);
