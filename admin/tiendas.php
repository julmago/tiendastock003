<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_any_role(['superadmin','admin'], '/admin/login.php');

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action = $_POST['action'] ?? '';
  $name = trim((string)($_POST['name'] ?? ''));
  $slug = slugify((string)($_POST['slug'] ?? ''));
  $markup = (float)($_POST['markup_percent'] ?? 100);

  if (!$name || !$slug) {
    $err="Completá nombre y slug.";
  } elseif ($action === 'create_wholesale') {
    $wholesalerId = (int)($_POST['wholesaler_id'] ?? 0);
    if (!$wholesalerId) $err="Elegí mayorista.";
    else {
      $pdo->prepare("INSERT INTO stores(wholesaler_id,store_type,name,slug,status,markup_percent) VALUES(?, 'wholesale', ?, ?, 'active', ?)")
          ->execute([$wholesalerId,$name,$slug,$markup]);
      $storeId = (int)$pdo->lastInsertId();

      $mpExtra = (float)setting($pdo,'mp_extra_percent','6');
      $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,?)")->execute([$storeId,'mercadopago',$mpExtra]);
      $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,0)")->execute([$storeId,'transfer']);
      $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,0,0)")->execute([$storeId,'cash_pickup',0]);

      $msg="Tienda mayorista creada.";
    }
  } elseif ($action === 'create_retail') {
    $retailerId = (int)($_POST['retailer_id'] ?? 0);
    if (!$retailerId) $err="Elegí minorista.";
    else {
      $pdo->prepare("INSERT INTO stores(retailer_id,store_type,name,slug,status,markup_percent) VALUES(?, 'retail', ?, ?, 'active', ?)")
          ->execute([$retailerId,$name,$slug,$markup]);
      $storeId = (int)$pdo->lastInsertId();

      $mpExtra = (float)setting($pdo,'mp_extra_percent','6');
      $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,?)")->execute([$storeId,'mercadopago',$mpExtra]);
      $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,1,0)")->execute([$storeId,'transfer']);
      $pdo->prepare("INSERT IGNORE INTO store_payment_methods(store_id,method,enabled,extra_percent) VALUES(?,?,0,0)")->execute([$storeId,'cash_pickup',0]);

      $msg="Tienda minorista creada.";
    }
  }
}

$wholesalers = $pdo->query("SELECT id, display_name FROM wholesalers ORDER BY id DESC")->fetchAll();
$retailers = $pdo->query("SELECT id, display_name FROM retailers ORDER BY id DESC")->fetchAll();
$stores = $pdo->query("SELECT id, name, slug, store_type, markup_percent, status FROM stores ORDER BY id DESC")->fetchAll();

page_header('Tiendas');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<h3>Crear tienda mayorista</h3>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='create_wholesale'>
<p>Mayorista:
<select name='wholesaler_id'><option value='0'>-- elegir --</option>";
foreach($wholesalers as $w){ echo "<option value='".h((string)$w['id'])."'>".h($w['display_name'])."</option>"; }
echo "</select></p>
<p>Nombre: <input name='name' style='width:420px'></p>
<p>Slug: <input name='slug' style='width:220px'></p>
<p>Markup %: <input name='markup_percent' style='width:120px' value='30'></p>
<button>Crear</button>
</form><hr>";

echo "<h3>Crear tienda minorista</h3>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='create_retail'>
<p>Minorista:
<select name='retailer_id'><option value='0'>-- elegir --</option>";
foreach($retailers as $r){ echo "<option value='".h((string)$r['id'])."'>".h($r['display_name'])."</option>"; }
echo "</select></p>
<p>Nombre: <input name='name' style='width:420px'></p>
<p>Slug: <input name='slug' style='width:220px'></p>
<p>Markup %: <input name='markup_percent' style='width:120px' value='100'></p>
<button>Crear</button>
</form><hr>";

echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Nombre</th><th>Slug</th><th>Tipo</th><th>Markup</th><th>Link</th></tr>";
foreach($stores as $st){
  $path = ($st['store_type']==='wholesale') ? '/mayorista/' : '/shop/';
  echo "<tr>
    <td>".h((string)$st['id'])."</td>
    <td>".h($st['name'])."</td>
    <td>".h($st['slug'])."</td>
    <td>".h($st['store_type'])."</td>
    <td>".h((string)$st['markup_percent'])."</td>
    <td><a target='_blank' href='".h($path).h($st['slug'])."/'>abrir</a></td>
  </tr>";
}
echo "</table>";
page_footer();
