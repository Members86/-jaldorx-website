<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function jdxCaptureError(string $code,int $status=400): void { http_response_code($status); echo json_encode(['ok'=>false,'error'=>$code]); exit; }
if($_SERVER['REQUEST_METHOD']!=='POST') jdxCaptureError('METHOD_NOT_ALLOWED',405);
require_once __DIR__.'/paypal-client.php';
if(!is_file(__DIR__.'/config.php')) jdxCaptureError('SERVER_CONFIG_MISSING',500);
require __DIR__.'/config.php';
if(!isset($pdo)||!($pdo instanceof PDO)) jdxCaptureError('DATABASE_NOT_CONFIGURED',500);
try {
  if(jdxPayPalConfig()['mode']!=='sandbox') jdxCaptureError('SANDBOX_ONLY',503);
  $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $data=json_decode(file_get_contents('php://input')?:'',true);
  $paypalId=is_array($data)?trim((string)($data['orderID']??'')):'';
  if(!preg_match('/^[A-Za-z0-9-]{5,100}$/',$paypalId)) jdxCaptureError('INVALID_PAYPAL_ORDER');
  $pdo->beginTransaction();
  $st=$pdo->prepare("SELECT * FROM orders WHERE paypal_order_id=? LIMIT 1 FOR UPDATE");
  $st->execute([$paypalId]); $order=$st->fetch(PDO::FETCH_ASSOC);
  if(!$order) { $pdo->rollBack(); jdxCaptureError('ORDER_NOT_FOUND',404); }
  if(($order['payment_status']??'')==='paid') { $pdo->commit(); echo json_encode(['ok'=>true,'orderNumber'=>$order['order_number']??'','alreadyPaid'=>true]); exit; }
  $paymentState=(string)($order['payment_status']??'');
  if(!in_array($paymentState,['pending','capturing'],true)) { $pdo->rollBack(); jdxCaptureError('ORDER_NOT_PAYABLE',409); }
  if($paymentState==='pending' && !empty($order['reservation_expires_at']) && strtotime((string)$order['reservation_expires_at']) < time()) {
    $pdo->rollBack(); jdxCaptureError('RESERVATION_EXPIRED',409);
  }
  if($paymentState==='pending') {
    $st=$pdo->prepare("UPDATE orders SET payment_status='capturing' WHERE id=? AND payment_status='pending'");
    $st->execute([(int)$order['id']]);
    if($st->rowCount()!==1) { $pdo->rollBack(); jdxCaptureError('ORDER_NOT_PAYABLE',409); }
  }
  $pdo->commit();

  // Reconcile with PayPal first. If an earlier capture succeeded but the response
  // was lost, do not issue a second capture or reset local state to pending.
  $paypalState=jdxPayPalRequest('GET','/v2/checkout/orders/'.$paypalId);
  $paypalStatus=(string)($paypalState['status']??'');
  if($paypalStatus==='COMPLETED') {
    $captured=$paypalState;
  } elseif($paypalStatus==='APPROVED') {
    $captured=jdxPayPalRequest('POST','/v2/checkout/orders/'.$paypalId.'/capture');
  } else {
    jdxCaptureError('PAYMENT_NOT_CONFIRMED',402);
  }
  $status=(string)($captured['status']??'');
  $unit=$captured['purchase_units'][0]??[];
  $capture=$unit['payments']['captures'][0]??[];
  $captureStatus=(string)($capture['status']??'');
  $amount=$capture['amount']??[];
  $expected=number_format((float)($order['total']??0),2,'.','');
  if($status!=='COMPLETED' || $captureStatus!=='COMPLETED' || ($amount['currency_code']??'')!=='EUR' || number_format((float)($amount['value']??0),2,'.','')!==$expected) {
    // Keep the order in 'capturing' until a later request can safely reconcile it.
    jdxCaptureError('PAYMENT_NOT_CONFIRMED',402);
  }
  $itemStmt=$pdo->prepare("SELECT COALESCE(SUM(tree_quantity),0) AS trees FROM order_items WHERE order_id=?");
  $itemStmt->execute([(int)$order['id']]); $qty=(int)$itemStmt->fetchColumn();
  if($qty<1 || $qty>40) throw new RuntimeException('ORDER_QUANTITY_INVALID');
  $product=$pdo->query("SELECT * FROM products WHERE sku='MYSTERY-DUFTBAUM' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  if(!$product) throw new RuntimeException('PRODUCT_NOT_FOUND');
  $productId=isset($product['id'])?(int)$product['id']:null;
  $invCols=array_column($pdo->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_ASSOC),'Field');
  $pdo->beginTransaction();
  $lock=$pdo->prepare("SELECT * FROM orders WHERE id=? LIMIT 1 FOR UPDATE"); $lock->execute([(int)$order['id']]); $locked=$lock->fetch(PDO::FETCH_ASSOC);
  if(!$locked || ($locked['payment_status']??'')!=='capturing') { $pdo->rollBack(); jdxCaptureError('ORDER_NOT_PAYABLE',409); }
  if(in_array('sku',$invCols,true)) { $s=$pdo->prepare("SELECT * FROM inventory WHERE sku=? LIMIT 1 FOR UPDATE"); $s->execute(['MYSTERY-DUFTBAUM']); }
  elseif($productId!==null && in_array('product_id',$invCols,true)) { $s=$pdo->prepare("SELECT * FROM inventory WHERE product_id=? LIMIT 1 FOR UPDATE"); $s->execute([$productId]); }
  else { $s=$pdo->query("SELECT * FROM inventory ORDER BY id ASC LIMIT 1 FOR UPDATE"); }
  $inv=$s->fetch(PDO::FETCH_ASSOC);
  if(!$inv || !isset($inv['reserved'],$inv['stock']) || (int)$inv['reserved']<$qty || (int)$inv['stock']<$qty) throw new RuntimeException('INVENTORY_STATE_INVALID');
  if(in_array('sku',$invCols,true)) { $u=$pdo->prepare("UPDATE inventory SET stock=stock-?, reserved=reserved-? WHERE sku=? AND stock>=? AND reserved>=?"); $u->execute([$qty,$qty,'MYSTERY-DUFTBAUM',$qty,$qty]); }
  elseif($productId!==null && in_array('product_id',$invCols,true)) { $u=$pdo->prepare("UPDATE inventory SET stock=stock-?, reserved=reserved-? WHERE product_id=? AND stock>=? AND reserved>=?"); $u->execute([$qty,$qty,$productId,$qty,$qty]); }
  else { $u=$pdo->prepare("UPDATE inventory SET stock=stock-?, reserved=reserved-? WHERE id=? AND stock>=? AND reserved>=?"); $u->execute([$qty,$qty,(int)$inv['id'],$qty,$qty]); }
  if($u->rowCount()!==1) throw new RuntimeException('INVENTORY_UPDATE_FAILED');
  $captureId=(string)($capture['id']??'');
  $up=$pdo->prepare("UPDATE orders SET payment_status='paid', status='paid', paypal_capture_id=? WHERE id=? AND payment_status='capturing'");
  $up->execute([$captureId,(int)$order['id']]);
  if($up->rowCount()!==1) throw new RuntimeException('ORDER_STATUS_UPDATE_FAILED');
  $pdo->commit();
  echo json_encode(['ok'=>true,'orderNumber'=>$order['order_number']??'','total'=>$expected]);
} catch(Throwable $e) {
  if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction()) $pdo->rollBack();
  error_log('JALDORX PayPal capture failed: '.$e->getMessage());
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>'PAYMENT_CAPTURE_FAILED']);
}
