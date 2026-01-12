<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('retailer','/minorista/login.php');

$st = $pdo->prepare("SELECT id FROM retailers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$retailer = $st->fetch();
if (!$retailer) exit('Minorista inválido');

$storeSt = $pdo->prepare("SELECT * FROM stores WHERE retailer_id=? AND store_type='retail' LIMIT 1");
$storeSt->execute([(int)$retailer['id']]);
$store = $storeSt->fetch();

// Cambio clave: minorista maneja una sola tienda retail.
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $name  = trim((string)($_POST['name'] ?? ''));
  $slug  = slugify((string)($_POST['slug'] ?? ''));
  $markup = (float)($_POST['markup_percent'] ?? 100);

  if (!$name || !$slug) {
    $err="Completá nombre y slug (solo letras/números).";
  } elseif ($store) {
    $pdo->prepare("UPDATE stores SET name=?, slug=?, markup_percent=? WHERE id=? AND retailer_id=?")
        ->execute([$name, $slug, $markup, (int)$store['id'], (int)$retailer['id']]);
    $msg="Tienda actualizada.";
  } else {
    $pdo->prepare("INSERT INTO stores(retailer_id,store_type,name,slug,status,markup_percent) VALUES(?, 'retail', ?, ?, 'active', ?)")
        ->execute([(int)$retailer['id'], $name, $slug, $markup]);
    $storeId = (int)$pdo->lastInsertId();

    $mpExtra = (float)setting($pdo,'mp_extra_percent','6');
    $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,?)")->execute([$storeId,'mercadopago',$mpExtra]);
    $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,0)")->execute([$storeId,'transfer']);
    $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,0,0)")->execute([$storeId,'cash_pickup',0]);
    $msg="Tienda creada.";
  }
  $storeSt->execute([(int)$retailer['id']]);
  $store = $storeSt->fetch();
}

page_header('Mi tienda minorista');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

if ($store) {
  echo "<p>Link público: <a target='_blank' href='/shop/".h($store['slug'])."/'>".h($store['slug'])."</a></p>";
}

echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Nombre: <input name='name' style='width:420px' value='".h((string)($store['name'] ?? ''))."'></p>
<p>Slug: <input name='slug' style='width:220px' value='".h((string)($store['slug'] ?? ''))."'></p>
<p>Markup %: <input name='markup_percent' style='width:120px' value='".h((string)($store['markup_percent'] ?? '100'))."'></p>
<button>Guardar</button>
</form>";
page_footer();
