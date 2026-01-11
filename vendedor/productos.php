<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/pricing.php';
csrf_check();
require_role('seller','/vendedor/login.php');

$st = $pdo->prepare("SELECT id FROM sellers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$seller = $st->fetch();
if (!$seller) exit('Seller inválido');

$storesSt = $pdo->prepare("SELECT id, name, slug, store_type, markup_percent FROM stores WHERE seller_id=? ORDER BY id DESC");
$storesSt->execute([(int)$seller['id']]);
$myStores = $storesSt->fetchAll();

$storeId = (int)($_GET['store_id'] ?? 0);
if (!$storeId && $myStores) $storeId = (int)$myStores[0]['id'];

$currentStore = null;
foreach($myStores as $ms){ if ((int)$ms['id'] === $storeId) $currentStore = $ms; }
if (!$currentStore) { page_header('Productos'); echo "<p>Primero creá una tienda.</p>"; page_footer(); exit; }

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'new'], true)) $action = 'list';
$listUrl = "productos.php?action=list&store_id=".h((string)$storeId);
$newUrl = "productos.php?action=new&store_id=".h((string)$storeId);

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'create') {
  $title = trim((string)($_POST['title'] ?? ''));
  $sku = trim((string)($_POST['sku'] ?? ''));
  $universalCode = trim((string)($_POST['universal_code'] ?? ''));
  if (!$title) $err="Falta título.";
  elseif ($universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) $err = "El código universal debe tener entre 8 y 14 números.";
  else {
    $pdo->prepare("INSERT INTO store_products(store_id,title,sku,universal_code,description,status,own_stock_qty,own_stock_price,manual_price)
                   VALUES(?,?,?,?,?, 'active',0,NULL,NULL)")
        ->execute([$storeId,$title,$sku?:null,$universalCode?:null,($_POST['description']??'')?:null]);
    $msg="Producto creado.";
  }
}

page_header('Vendedor - Productos');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<div style='display:flex; align-items:center; justify-content:space-between; gap:12px;'>
  <h2 style='margin:0;'>Vendedor - Productos</h2>
  <div><a href='".$newUrl."'>Nuevo</a> | <a href='".$listUrl."'>Listado</a></div>
</div>";

if ($action === 'list') {
  echo "<form method='get'>
  <input type='hidden' name='action' value='list'>
  <select name='store_id'>";
  foreach($myStores as $ms){
    $sel = ((int)$ms['id']===$storeId) ? "selected" : "";
    echo "<option value='".h((string)$ms['id'])."' $sel>".h($ms['name'])." (".h($ms['store_type']).")</option>";
  }
  echo "</select> <button>Ver</button>
  </form><hr>";

  $stp = $pdo->prepare("SELECT * FROM store_products WHERE store_id=? ORDER BY id DESC");
  $stp->execute([$storeId]);
  $storeProducts = $stp->fetchAll();

  echo "<h3>Listado</h3>";
  if (!$storeProducts) { echo "<p>Sin productos.</p>"; page_footer(); exit; }

  echo "<table border='1' cellpadding='6' cellspacing='0'>
  <tr><th>ID</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Stock prov</th><th>Own qty</th><th>Own $</th><th>Manual $</th><th>Precio actual</th></tr>";
  foreach($storeProducts as $sp){
    $provStock = provider_stock_sum($pdo, (int)$sp['id']);
    $sell = current_sell_price($pdo, $currentStore, $sp);
    $stockTotal = $provStock + (int)$sp['own_stock_qty'];
    $sellTxt = ($sell>0) ? '$'.number_format($sell,2,',','.') : 'Sin stock';

    $editUrl = "producto.php?id=".h((string)$sp['id'])."&store_id=".h((string)$storeId);
    echo "<tr>
      <td>".h((string)$sp['id'])."</td>
      <td><a href='".$editUrl."'>".h($sp['title'])."</a></td>
      <td>".h($sp['sku']??'')."</td>
      <td>".h($sp['universal_code']??'')."</td>
      <td>".h((string)$provStock)."</td>
      <td>".h((string)$sp['own_stock_qty'])."</td>
      <td>".h((string)($sp['own_stock_price']??''))."</td>
      <td>".h((string)($sp['manual_price']??''))."</td>
      <td>".h($sellTxt)." (total: ".h((string)$stockTotal).")</td>
    </tr>";
  }
  echo "</table>";
}

if ($action === 'new') {
  $providerQuery = trim((string)($_GET['provider_q'] ?? ''));
  $providerProducts = [];
  if ($providerQuery !== '') {
    $like = "%{$providerQuery}%";
    $searchSt = $pdo->prepare("
      SELECT pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, pp.description, p.display_name AS provider_name,
             COALESCE(SUM(GREATEST(ws.qty_available - ws.qty_reserved,0)),0) AS stock
      FROM provider_products pp
      JOIN providers p ON p.id=pp.provider_id
      LEFT JOIN warehouse_stock ws ON ws.provider_product_id = pp.id
      WHERE pp.status='active' AND p.status='active'
        AND (pp.title LIKE ? OR pp.sku LIKE ? OR pp.universal_code LIKE ?)
      GROUP BY pp.id, pp.title, pp.sku, pp.universal_code, pp.base_price, pp.description, p.display_name
      HAVING stock > 0
      ORDER BY pp.id DESC
      LIMIT 20
    ");
    $searchSt->execute([$like, $like, $like]);
    $providerProducts = $searchSt->fetchAll();
  }

  echo "<h3>Crear desde cero</h3>
  <form method='post' id='create-form'>
  <input type='hidden' name='csrf' value='".h(csrf_token())."'>
  <input type='hidden' name='action' value='create'>
  <p>Título: <input id='create-title' name='title' style='width:520px'></p>
  <p>SKU: <input id='create-sku' name='sku' style='width:220px'></p>
  <p>Código universal (8-14 dígitos): <input id='create-universal' name='universal_code' style='width:220px'></p>
  <p>Descripción:<br><textarea id='create-description' name='description' rows='3' style='width:90%'></textarea></p>
  <p id='copy-message' style='color:green; display:none;'></p>
  <button>Crear</button>
  </form><hr>";

  echo "<h3>Copiar desde proveedor</h3>
  <form method='get' action='productos.php'>
  <input type='hidden' name='action' value='new'>
  <input type='hidden' name='store_id' value='".h((string)$storeId)."'>
  <input name='provider_q' placeholder='Buscar producto del proveedor...' value='".h($providerQuery)."' style='width:420px'>
  <button>Buscar</button>
  </form>";

  echo "<table border='1' cellpadding='6' cellspacing='0' style='margin-top:10px; width:100%; max-width:1200px;'>
  <tr>
    <th>Proveedor</th>
    <th>Título</th>
    <th>SKU</th>
    <th>Código universal</th>
    <th>Stock</th>
    <th>Precio</th>
    <th>Acciones</th>
  </tr>";

  if (!$providerProducts) {
    echo "<tr><td colspan='7'>No se encontraron productos.</td></tr>";
  } else {
    foreach ($providerProducts as $pp) {
      $priceTxt = ($pp['base_price'] !== null && $pp['base_price'] !== '') ? '$'.h(number_format((float)$pp['base_price'],2,',','.')) : '';
      echo "<tr>
        <td>".h($pp['provider_name'])."</td>
        <td>".h($pp['title'])."</td>
        <td>".h($pp['sku'] ?? '')."</td>
        <td>".h($pp['universal_code'] ?? '')."</td>
        <td>".h((string)$pp['stock'])."</td>
        <td>".$priceTxt."</td>
        <td>
          <button type='button' class='copy-provider-btn'
            data-title='".h($pp['title'])."'
            data-sku='".h($pp['sku'] ?? '')."'
            data-universal='".h($pp['universal_code'] ?? '')."'
            data-description='".h($pp['description'] ?? '')."'
          >Copiar</button>
        </td>
      </tr>";
    }
  }
  echo "</table><hr>
  <script>
  (function() {
    var buttons = document.querySelectorAll('.copy-provider-btn');
    if (!buttons.length) return;
    var titleInput = document.getElementById('create-title');
    var skuInput = document.getElementById('create-sku');
    var universalInput = document.getElementById('create-universal');
    var descriptionInput = document.getElementById('create-description');
    var message = document.getElementById('copy-message');
    var form = document.getElementById('create-form');

    buttons.forEach(function(button) {
      button.addEventListener('click', function() {
        if (titleInput) titleInput.value = button.dataset.title || '';
        if (skuInput) skuInput.value = button.dataset.sku || '';
        if (universalInput) universalInput.value = button.dataset.universal || '';
        if (descriptionInput) descriptionInput.value = button.dataset.description || '';
        if (message) {
          message.textContent = 'Datos cargados desde proveedor. Revisá y presioná Crear para publicar.';
          message.style.display = 'block';
        }
        if (form && form.scrollIntoView) {
          form.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
        if (titleInput && titleInput.focus) titleInput.focus();
      });
    });
  })();
  </script>";
}
page_footer();
