<?php
require __DIR__.'/../../config.php';
csrf_check();
require_role('retailer','/minorista/login.php');

header('Content-Type: application/json; charset=utf-8');

$productId = (int)($_POST['product_id'] ?? 0);
$linkedId = (int)($_POST['linked_product_id'] ?? 0);

if (!$productId || !$linkedId) {
  http_response_code(400);
  echo json_encode(['error' => 'Datos incompletos.']);
  exit;
}

$retailerSt = $pdo->prepare("SELECT id FROM retailers WHERE user_id=? LIMIT 1");
$retailerSt->execute([(int)($_SESSION['uid'] ?? 0)]);
$retailer = $retailerSt->fetch();
if (!$retailer) {
  http_response_code(403);
  echo json_encode(['error' => 'Acceso denegado.']);
  exit;
}

$st = $pdo->prepare("SELECT sp.id FROM store_products sp JOIN stores s ON s.id=sp.store_id WHERE sp.id=? AND s.retailer_id=? LIMIT 1");
$st->execute([$productId, (int)$retailer['id']]);
if (!$st->fetch()) {
  http_response_code(403);
  echo json_encode(['error' => 'Acceso denegado.']);
  exit;
}

$wholesalerLink = $pdo->prepare("SELECT wholesaler_id FROM retailer_wholesaler WHERE retailer_id=? LIMIT 1");
$wholesalerLink->execute([(int)$retailer['id']]);
$linkedWholesalerId = (int)($wholesalerLink->fetchColumn() ?? 0);
if ($linkedWholesalerId <= 0) {
  http_response_code(400);
  echo json_encode(['error' => 'No hay mayorista vinculado.']);
  exit;
}

$providerLink = $pdo->prepare("SELECT provider_id FROM wholesaler_provider WHERE wholesaler_id=?");
$providerLink->execute([$linkedWholesalerId]);
$providerIds = array_map('intval', $providerLink->fetchAll(PDO::FETCH_COLUMN));
if (!$providerIds) {
  http_response_code(400);
  echo json_encode(['error' => 'El mayorista no tiene proveedores vinculados.']);
  exit;
}

$placeholders = implode(',', array_fill(0, count($providerIds), '?'));

$existsSt = $pdo->prepare("
  SELECT sps.id
  FROM store_product_sources sps
  JOIN provider_products pp ON pp.id = sps.provider_product_id
  LEFT JOIN providers p ON p.id = pp.provider_id
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
  WHERE sps.store_product_id = ? AND sps.provider_product_id = ? AND sps.enabled = 1
  LIMIT 1
");
$existsSt->execute([$productId, $linkedId]);
$existingLink = $existsSt->fetch();
if ($existingLink) {
  http_response_code(409);
  echo json_encode(['error' => 'Ya está vinculado.']);
  exit;
}

$ppSt = $pdo->prepare("
  SELECT pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, p.display_name AS provider_name,
         COALESCE(SUM(GREATEST(ws.qty_available - ws.qty_reserved,0)),0) AS stock
  FROM provider_products pp
  JOIN providers p ON p.id=pp.provider_id
  LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
  WHERE pp.id=? AND pp.status='active' AND p.status='active'
    AND pp.provider_id IN ({$placeholders})
  GROUP BY pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, p.display_name
  HAVING stock > 0
  LIMIT 1
");
$ppSt->execute(array_merge([$linkedId], $providerIds));
$pp = $ppSt->fetch();
if (!$pp) {
  http_response_code(400);
  echo json_encode(['error' => 'Producto mayorista inválido o sin stock.']);
  exit;
}

try {
  $pdo->prepare("INSERT INTO store_product_sources(store_product_id,provider_product_id,enabled) VALUES(?,?,1)")
      ->execute([$productId, $linkedId]);
} catch (Throwable $e) {
  $existsSt->execute([$productId, $linkedId]);
  $existingLink = $existsSt->fetch();
  if ($existingLink) {
    http_response_code(409);
    echo json_encode(['error' => 'Ya está vinculado.']);
    exit;
  }
  http_response_code(500);
  echo json_encode(['error' => 'No se pudo vincular.']);
  exit;
}

$response = [
  'ok' => true,
  'item' => [
    'id' => (int)$pp['id'],
    'title' => (string)$pp['title'],
    'sku' => (string)($pp['sku'] ?? ''),
    'universal_code' => (string)($pp['universal_code'] ?? ''),
    'price' => $pp['base_price'] !== null ? (float)$pp['base_price'] : null,
    'provider_name' => (string)($pp['provider_name'] ?? ''),
    'stock' => (int)$pp['stock'],
  ],
];

echo json_encode($response);
