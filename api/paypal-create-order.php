<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jdxJsonError(string $code, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $code]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') jdxJsonError('METHOD_NOT_ALLOWED', 405);
require_once __DIR__ . '/paypal-client.php';
if (!is_file(__DIR__ . '/config.php')) jdxJsonError('SERVER_CONFIG_MISSING', 500);
require __DIR__ . '/config.php';
if (!isset($pdo) || !($pdo instanceof PDO)) jdxJsonError('DATABASE_NOT_CONFIGURED', 500);

try {
    $cfg = jdxPayPalConfig();
    if ($cfg['mode'] !== 'sandbox') jdxJsonError('SANDBOX_ONLY', 503);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rawBody=file_get_contents('php://input') ?: '';
    if(strlen($rawBody)>20000) jdxJsonError('REQUEST_TOO_LARGE',413);
    $data = json_decode($rawBody, true);
    if (!is_array($data)) jdxJsonError('INVALID_JSON');
    $customer = $data['customer'] ?? [];
    $items = $data['items'] ?? [];
    if(!is_array($customer)) jdxJsonError('INVALID_CUSTOMER_DATA');
    if(!is_array($items) || count($items)>20) jdxJsonError('INVALID_CART');
    foreach (['firstName','lastName','street','postalCode','city','email'] as $key) {
        if (!is_string($customer[$key] ?? null) || trim($customer[$key]) === '') jdxJsonError('MISSING_CUSTOMER_DATA');
    }
    if (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) jdxJsonError('INVALID_EMAIL');
    if (($data['acceptedTerms'] ?? false) !== true) jdxJsonError('TERMS_NOT_ACCEPTED', 422);
    if (!is_array($items) || !$items) jdxJsonError('EMPTY_CART');

    $tiers = [['max'=>4,'price'=>4.49],['max'=>9,'price'=>4.29],['max'=>19,'price'=>4.19],['max'=>29,'price'=>4.09],['max'=>39,'price'=>3.85]];
    $boxes = ['two'=>['name'=>'2 gute garantiert','price'=>43.90], 'four'=>['name'=>'4 gute garantiert','price'=>45.90], 'six'=>['name'=>'6 gute garantiert','price'=>47.90], 'nine'=>['name'=>'9 gute + 1 schlechter','price'=>49.90]];
    $normalPrice = static function(int $q) use ($tiers): float { if ($q===40) return 149.90; foreach($tiers as $t) if($q <= $t['max']) return $t['price']; throw new RuntimeException('INVALID_QUANTITY'); };
    $normalized=[]; $treeQty=0; $subtotal=0.0;
    // Reject malformed/unknown cart entries instead of silently dropping them.
    foreach($items as $item) {
        if (!is_array($item)) jdxJsonError('INVALID_CART_ITEM');
        $mode=$item['mode'] ?? '';
        if ($mode === 'normal') {
            $q=(int)($item['qty'] ?? 0);
            if($q<1 || $q>40) jdxJsonError('INVALID_QUANTITY');
            $treeQty += $q;
        } elseif ($mode === 'box') {
            $boxType=$item['boxType'] ?? '';
            if (!is_string($boxType) || !isset($boxes[$boxType])) jdxJsonError('INVALID_BOX_TYPE');
            $bq=(int)($item['boxQty'] ?? 0);
            if($bq<1 || $bq>4) jdxJsonError('INVALID_SET_QUANTITY');
            $treeQty += $bq*10;
        } else {
            jdxJsonError('INVALID_CART_ITEM');
        }
    }
    if($treeQty<1 || $treeQty>40) jdxJsonError('MAX_40_TREES');
    // Aggregate normal singles so splitting them into multiple cart entries
    // cannot bypass the quantity discount tiers.
    $normalTotal=0;
    foreach($items as $item) if(($item['mode']??'')==='normal') $normalTotal+=(int)$item['qty'];
    // Only after validating the entire cart do we calculate server-side prices.
    $treeQty=0; $normalAdded=false;
    foreach($items as $item) {
        if (($item['mode'] ?? '') === 'normal') {
            if($normalAdded) continue;
            $normalAdded=true;
            $q=$normalTotal;
            $unit=$normalPrice($q); $line=$q===40?149.90:$q*$unit;
            $normalized[]=['type'=>'normal','code'=>'MYSTERY-DUFTBAUM','name'=>'MYSTERY DUFTBAUM','quantity'=>$q,'unit_price'=>$q===40?149.90/40:$unit,'line_total'=>$line,'tree_quantity'=>$q];
            $treeQty += $q; $subtotal += $line;
        } elseif (($item['mode'] ?? '') === 'box') {
            $bq=(int)($item['boxQty'] ?? 0); if($bq<1 || $bq>4) jdxJsonError('INVALID_SET_QUANTITY');
            $b=$boxes[$item['boxType']]; $q=$bq*10; $line=$b['price']*$bq;
            $normalized[]=['type'=>'box','code'=>'set_'.$item['boxType'],'name'=>'Mystery 10er-Set · '.$b['name'],'quantity'=>$bq,'unit_price'=>$b['price'],'line_total'=>$line,'tree_quantity'=>$q];
            $treeQty += $q; $subtotal += $line;
        }
    }
    if($treeQty<1 || $treeQty>40) jdxJsonError('MAX_40_TREES');
    $shipping = $subtotal >= 99 ? 0.00 : ($treeQty<=10 ? 3.49 : 5.99);
    $total = round($subtotal+$shipping,2);
    $product=$pdo->query("SELECT * FROM products WHERE sku='MYSTERY-DUFTBAUM' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if(!$product) jdxJsonError('PRODUCT_NOT_FOUND',500);
    $productId=isset($product['id'])?(int)$product['id']:null;
    $cols=[];
    foreach(['products','inventory','orders','order_items'] as $table) {
        $cols[$table]=array_column($pdo->query("SHOW COLUMNS FROM ".$table)->fetchAll(PDO::FETCH_ASSOC),'Field');
    }
    // Release reservations from abandoned sandbox checkouts before checking available stock.
    $expired=$pdo->prepare("SELECT id FROM orders WHERE payment_status='pending' AND reservation_expires_at IS NOT NULL AND reservation_expires_at < NOW() ORDER BY id ASC LIMIT 100");
    $expired->execute();
    foreach($expired->fetchAll(PDO::FETCH_COLUMN) as $expiredId) {
      $pdo->beginTransaction();
      try {
        $lockOrder=$pdo->prepare("SELECT id,payment_status,reservation_expires_at,(reservation_expires_at < NOW()) AS is_expired FROM orders WHERE id=? FOR UPDATE");
        $lockOrder->execute([(int)$expiredId]); $expiredOrder=$lockOrder->fetch(PDO::FETCH_ASSOC);
        // Re-check expiry after locking to avoid racing a concurrent capture.
        if($expiredOrder && ($expiredOrder['payment_status']??'')==='pending' &&
           !empty($expiredOrder['reservation_expires_at']) &&
           (int)($expiredOrder['is_expired']??0)===1) {
          $q=$pdo->prepare("SELECT COALESCE(SUM(tree_quantity),0) FROM order_items WHERE order_id=?");
          $q->execute([(int)$expiredId]); $expiredQty=(int)$q->fetchColumn();
          if($expiredQty>0) {
            if(in_array('sku',$cols['inventory'],true)) {
              $release=$pdo->prepare("UPDATE inventory SET reserved=reserved-? WHERE sku='MYSTERY-DUFTBAUM' AND reserved>=?");
              $release->execute([$expiredQty,$expiredQty]);
            } elseif($productId!==null && in_array('product_id',$cols['inventory'],true)) {
              $release=$pdo->prepare("UPDATE inventory SET reserved=reserved-? WHERE product_id=? AND reserved>=?");
              $release->execute([$expiredQty,$productId,$expiredQty]);
            } else {
              $invIdStmt=$pdo->query("SELECT id FROM inventory ORDER BY id ASC LIMIT 1 FOR UPDATE");
              $invId=(int)$invIdStmt->fetchColumn();
              if($invId<1) throw new RuntimeException('INVENTORY_SCHEMA_UNSUPPORTED');
              $release=$pdo->prepare("UPDATE inventory SET reserved=reserved-? WHERE id=? AND reserved>=?");
              $release->execute([$expiredQty,$invId,$expiredQty]);
            }
            if($release->rowCount()!==1) throw new RuntimeException('RESERVATION_RELEASE_FAILED');
          }
          $pdo->prepare("UPDATE orders SET payment_status='expired', status='expired' WHERE id=? AND payment_status='pending'")->execute([(int)$expiredId]);
        }
        $pdo->commit();
      } catch(Throwable $cleanupError) {
        if($pdo->inTransaction()) $pdo->rollBack();
        throw $cleanupError;
      }
    }

    $pdo->beginTransaction();
    if(in_array('sku',$cols['inventory'],true)) { $s=$pdo->prepare("SELECT * FROM inventory WHERE sku=? LIMIT 1 FOR UPDATE"); $s->execute(['MYSTERY-DUFTBAUM']); }
    elseif($productId!==null && in_array('product_id',$cols['inventory'],true)) { $s=$pdo->prepare("SELECT * FROM inventory WHERE product_id=? LIMIT 1 FOR UPDATE"); $s->execute([$productId]); }
    else { $s=$pdo->query("SELECT * FROM inventory ORDER BY id ASC LIMIT 1 FOR UPDATE"); }
    $inv=$s->fetch(PDO::FETCH_ASSOC);
    if(!$inv || !isset($inv['stock']) || !isset($inv['reserved'])) throw new RuntimeException('INVENTORY_RESERVATION_SCHEMA_MISSING');
    if((int)$inv['stock']-(int)$inv['reserved'] < $treeQty) { $pdo->rollBack(); jdxJsonError('INSUFFICIENT_STOCK',409); }
    $orderNumber='JX-'.random_int(100000,999999);
    $clean=static fn($v,$max=255)=>mb_substr(trim((string)$v),0,$max);
    // Use the database clock for reservation deadlines, matching expiry checks.
    $reservationDeadline=(string)$pdo->query("SELECT DATE_ADD(NOW(), INTERVAL 30 MINUTE)")->fetchColumn();
    if($reservationDeadline==='') throw new RuntimeException('RESERVATION_DEADLINE_FAILED');
    $values=[
      'order_number'=>$orderNumber,'customer_name'=>$clean($customer['firstName'],100).' '.$clean($customer['lastName'],100),
      'street'=>$clean($customer['street']),'postal_code'=>$clean($customer['postalCode'],20),'city'=>$clean($customer['city'],120),
      'country'=>'Deutschland','customer_email'=>$clean($customer['email']),'payment_method'=>'PayPal Sandbox',
      'quantity'=>$treeQty,'subtotal'=>number_format($subtotal,2,'.',''),'shipping'=>number_format($shipping,2,'.',''),
      'total'=>number_format($total,2,'.',''),'status'=>'pending','payment_status'=>'pending',
      'reservation_expires_at'=>$reservationDeadline
    ];
    $insert=[]; foreach($values as $k=>$v) if(in_array($k,$cols['orders'],true)) $insert[$k]=$v;
    if(!$insert) throw new RuntimeException('ORDER_SCHEMA_INVALID');
    $sql='INSERT INTO orders ('.implode(',',array_keys($insert)).') VALUES ('.implode(',',array_fill(0,count($insert),'?')).')';
    $st=$pdo->prepare($sql); $st->execute(array_values($insert)); $orderId=(int)$pdo->lastInsertId();
    foreach($normalized as $line) {
      $iv=['order_id'=>$orderId,'product_id'=>$productId,'item_type'=>$line['type'],'sku'=>$line['code'],'product_name'=>$line['name'],'variant'=>$line['type'],'quantity'=>$line['quantity'],'unit_price'=>number_format($line['unit_price'],2,'.',''),'line_total'=>number_format($line['line_total'],2,'.',''),'tree_quantity'=>$line['tree_quantity']];
      $row=[]; foreach($iv as $k=>$v) if(in_array($k,$cols['order_items'],true)) $row[$k]=$v;
      $st=$pdo->prepare('INSERT INTO order_items ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')'); $st->execute(array_values($row));
    }
    if(in_array('sku',$cols['inventory'],true)) { $u=$pdo->prepare("UPDATE inventory SET reserved=reserved+? WHERE sku=? AND stock-reserved>=?"); $u->execute([$treeQty,'MYSTERY-DUFTBAUM',$treeQty]); }
    elseif($productId!==null && in_array('product_id',$cols['inventory'],true)) { $u=$pdo->prepare("UPDATE inventory SET reserved=reserved+? WHERE product_id=? AND stock-reserved>=?"); $u->execute([$treeQty,$productId,$treeQty]); }
    else { $u=$pdo->prepare("UPDATE inventory SET reserved=reserved+? WHERE id=? AND stock-reserved>=?"); $u->execute([$treeQty,(int)$inv['id'],$treeQty]); }
    if($u->rowCount()!==1) throw new RuntimeException('STOCK_CHANGED');
    $pdo->commit();

    $paypal = jdxPayPalRequest('POST','/v2/checkout/orders',[
      'intent'=>'CAPTURE',
      'purchase_units'=>[[
        'reference_id'=>(string)$orderId,'custom_id'=>$orderNumber,
        'description'=>'JALDORX Mystery Duftbaum Bestellung '.$orderNumber,
        'amount'=>['currency_code'=>'EUR','value'=>number_format($total,2,'.',''),
          'breakdown'=>['item_total'=>['currency_code'=>'EUR','value'=>number_format($subtotal,2,'.','')],'shipping'=>['currency_code'=>'EUR','value'=>number_format($shipping,2,'.','')]]],
        'shipping'=>[
          'name'=>['full_name'=>$clean($customer['firstName'],100).' '.$clean($customer['lastName'],100)],
          'address'=>['address_line_1'=>$clean($customer['street'],255),'admin_area_2'=>$clean($customer['city'],120),'postal_code'=>$clean($customer['postalCode'],20),'country_code'=>'DE']
        ]
      ]],
      'application_context'=>['brand_name'=>'JALDORX','shipping_preference'=>'SET_PROVIDED_ADDRESS','user_action'=>'PAY_NOW','return_url'=>'https://jaldorx.de/checkout.html?paypal=return','cancel_url'=>'https://jaldorx.de/checkout.html?paypal=cancel']
    ]);
    $paypalId=(string)($paypal['id']??'');
    if($paypalId==='') throw new RuntimeException('PAYPAL_ORDER_CREATE_FAILED');
    $u=$pdo->prepare("UPDATE orders SET paypal_order_id=? WHERE id=? AND payment_status='pending'");
    $u->execute([$paypalId,$orderId]);
    if($u->rowCount()!==1) throw new RuntimeException('LOCAL_ORDER_LINK_FAILED');
    echo json_encode(['ok'=>true,'orderID'=>$paypalId,'orderNumber'=>$orderNumber,'total'=>$total]);
} catch(Throwable $e) {
    if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    // If PayPal order creation fails after a reservation was committed, release that reservation.
    if(isset($pdo,$orderId,$treeQty,$cols) && $pdo instanceof PDO && $orderId>0) {
      try {
        $pdo->beginTransaction();
        $q=$pdo->prepare("SELECT payment_status FROM orders WHERE id=? FOR UPDATE"); $q->execute([$orderId]); $st=$q->fetch(PDO::FETCH_ASSOC);
        if($st && ($st['payment_status']??'')==='pending') {
          if(in_array('sku',$cols['inventory']??[],true)) { $release=$pdo->prepare("UPDATE inventory SET reserved=reserved-? WHERE sku='MYSTERY-DUFTBAUM' AND reserved>=?"); $release->execute([$treeQty,$treeQty]); }
          elseif(isset($productId) && $productId!==null && in_array('product_id',$cols['inventory']??[],true)) { $release=$pdo->prepare("UPDATE inventory SET reserved=reserved-? WHERE product_id=? AND reserved>=?"); $release->execute([$treeQty,$productId,$treeQty]); }
          else {
            // Same id-based fallback as the reservation path above. Release only
            // the exact row and only when enough quantity is still reserved.
            $invIdStmt=$pdo->query("SELECT id FROM inventory ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $invId=(int)$invIdStmt->fetchColumn();
            if($invId<1) throw new RuntimeException('INVENTORY_SCHEMA_UNSUPPORTED');
            $release=$pdo->prepare("UPDATE inventory SET reserved=reserved-? WHERE id=? AND reserved>=?");
            $release->execute([$treeQty,$invId,$treeQty]);
            if($release->rowCount()!==1) throw new RuntimeException('RESERVATION_RELEASE_FAILED');
          }
          if(!isset($release) || $release->rowCount()!==1) throw new RuntimeException('RESERVATION_RELEASE_FAILED');
          $pdo->prepare("UPDATE orders SET payment_status='failed', status='failed' WHERE id=?")->execute([$orderId]);
        }
        $pdo->commit();
      } catch(Throwable $cleanupError) { if($pdo->inTransaction()) $pdo->rollBack(); error_log('JALDORX reservation cleanup failed: '.$cleanupError->getMessage()); }
    }
    error_log('JALDORX PayPal create-order failed: '.$e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>'PAYPAL_ORDER_CREATE_FAILED']);
}
