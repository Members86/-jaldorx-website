<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/paypal-client.php';
try {
  $config=jdxPayPalConfig();
  if($config['mode']!=='sandbox') { http_response_code(503); echo json_encode(['ok'=>false]); exit; }
  echo json_encode(['ok'=>true,'mode'=>'sandbox','clientId'=>$config['client_id']]);
} catch(Throwable $e) {
  http_response_code(503);
  echo json_encode(['ok'=>false,'error'=>'PAYPAL_NOT_CONFIGURED']);
}
