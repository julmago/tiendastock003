<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require __DIR__.'/../_inc/pricing.php';
require __DIR__.'/../lib/product_images.php';
csrf_check();
require_role('retailer','/minorista/login.php');

$st = $pdo->prepare("SELECT id FROM retailers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$retailer = $st->fetch();
if (!$retailer) exit('Minorista inválido');

$productId = (int)($_GET['id'] ?? 0);
if (!$productId) { page_header('Producto'); echo "<p>Producto inválido.</p>"; page_footer(); exit; }

$productSt = $pdo->prepare("SELECT sp.*, s.name AS store_name, s.store_type, s.markup_percent, s.id AS store_id
  FROM store_products sp
  JOIN stores s ON s.id=sp.store_id
  WHERE sp.id=? AND s.retailer_id=? LIMIT 1");
$productSt->execute([$productId,(int)$retailer['id']]);
$product = $productSt->fetch();
if (!$product) { page_header('Producto'); echo "<p>Producto inválido.</p>"; page_footer(); exit; }

$storeId = (int)$product['store_id'];
$product_images = [];
$image_errors = [];

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update_info') {
  $title = trim((string)($_POST['title'] ?? ''));
  $sku = trim((string)($_POST['sku'] ?? ''));
  $universalCode = trim((string)($_POST['universal_code'] ?? ''));
  $description = trim((string)($_POST['description'] ?? ''));

  if (!$title) $err = "Falta título.";
  elseif ($universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) $err = "El código universal debe tener entre 8 y 14 números.";
  else {
    $pdo->prepare("UPDATE store_products SET title=?, sku=?, universal_code=?, description=? WHERE id=? AND store_id=?")
        ->execute([$title, $sku?:null, $universalCode?:null, $description?:null, $productId, $storeId]);
    $msg = "Producto actualizado.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update_stock') {
  $ownQty = (int)($_POST['own_stock_qty'] ?? 0);
  $ownPriceRaw = trim((string)($_POST['own_stock_price'] ?? ''));
  $manualRaw = trim((string)($_POST['manual_price'] ?? ''));
  $ownPriceVal = ($ownPriceRaw === '') ? null : (float)$ownPriceRaw;
  $manualVal = ($manualRaw === '') ? null : (float)$manualRaw;

  $pdo->prepare("UPDATE store_products SET own_stock_qty=?, own_stock_price=?, manual_price=? WHERE id=? AND store_id=?")
      ->execute([$ownQty, $ownPriceVal, $manualVal, $productId, $storeId]);
  if (empty($err)) {
    $msg = "Stock actualizado.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'update_images') {
  $upload_dir = __DIR__.'/../uploads/store_products/'.$productId;
  if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0775, true);
  }
  product_images_process_uploads($pdo, 'store_product', $productId, $_FILES['images'] ?? [], $upload_dir, $image_sizes, $max_image_size_bytes, $image_errors);
  product_images_apply_order($pdo, 'store_product', $productId, (string)($_POST['images_order'] ?? ''));
  if (!$image_errors) {
    $msg = "Imágenes actualizadas.";
  }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '') === 'delete_image') {
  $image_id = isset($_POST['delete_image_id']) ? (int)$_POST['delete_image_id'] : 0;
  if ($image_id <= 0) {
    $err = "Imagen inválida.";
  } else {
    $upload_dir = __DIR__.'/../uploads/store_products/'.$productId;
    if (product_images_delete($pdo, 'store_product', $productId, $image_id, $upload_dir, $image_sizes)) {
      $msg = "Imagen eliminada.";
    } else {
      $err = "Imagen inválida.";
    }
  }
}

$productSt->execute([$productId,(int)$retailer['id']]);
$product = $productSt->fetch();
$product_images = product_images_fetch($pdo, 'store_product', $productId);

$sellDetails = current_sell_price_details($pdo, $product, $product);
$sell = (float)$sellDetails['price'];
$stockTotal = (int)$product['own_stock_qty'];
$sellTxt = ($sell>0) ? '$'.number_format($sell,2,',','.') : 'Sin stock';
$priceSource = $sellDetails['price_source'] ?? 'provider';
$priceSourceLabel = 'automático';
if ($priceSource === 'manual') {
  $priceSourceLabel = 'manual';
} elseif ($priceSource === 'own') {
  $priceSourceLabel = 'stock propio';
}

page_header('Producto');
// Cambio clave: edición minorista sin vínculos a proveedor.
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
if (!empty($image_errors)) {
  echo "<p style='color:#b00'>".h(implode(' ', $image_errors))."</p>";
}

echo "<p><a href='productos.php'>← Volver al listado</a></p>";

echo "<h3>Editar producto</h3>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='update_info'>
<p>Título: <input name='title' value='".h($product['title'])."' style='width:520px'></p>
<p>SKU: <input name='sku' value='".h((string)($product['sku']??''))."' style='width:220px'></p>
<p>Código universal (8-14 dígitos):
  <input name='universal_code' value='".h((string)($product['universal_code']??''))."' style='width:220px'>
</p>
<p>Descripción:<br><textarea name='description' rows='4' style='width:90%'>".h((string)($product['description']??''))."</textarea></p>
<button>Guardar cambios</button>
</form><hr>";

echo "<h3>Imágenes</h3>
<form method='post' enctype='multipart/form-data'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' id='images_action' value='update_images'>
<input type='hidden' name='delete_image_id' id='delete_image_id' value=''>
<p><input type='file' name='images[]' multiple accept='image/*'></p>
<input type='hidden' name='images_order' id='images_order' value=''>
<ul id='images-list'>";
if ($product_images) {
  foreach ($product_images as $index => $image) {
    $thumb = product_image_with_size($image['filename_base'], 150);
    $thumb_url = "/uploads/store_products/".h((string)$productId)."/".h($thumb);
    $cover_label = $index === 0 ? "Portada" : "";
    echo "<li data-id='".h((string)$image['id'])."'>
<img src='".$thumb_url."' alt='' width='80' height='80'>
 <span class='cover-label'>".h($cover_label)."</span>
 <button type='button' class='move-up'>↑</button>
 <button type='button' class='move-down'>↓</button>
 <button type='button' class='img-delete' data-image-id='".h((string)$image['id'])."'>X</button>
</li>";
  }
} else {
  echo "<li>No hay imágenes cargadas.</li>";
}
echo "</ul>
<button>Guardar imágenes</button>
</form>
<script>
(function() {
  var list = document.getElementById('images-list');
  var orderInput = document.getElementById('images_order');
  var deleteInput = document.getElementById('delete_image_id');
  var actionInput = document.getElementById('images_action');
  var form = list ? list.closest('form') : null;
  if (!list || !orderInput) return;

  function updateOrder() {
    var ids = [];
    var items = list.querySelectorAll('li[data-id]');
    items.forEach(function(item, index) {
      ids.push(item.getAttribute('data-id'));
      var label = item.querySelector('.cover-label');
      if (label) {
        label.textContent = index === 0 ? 'Portada' : '';
      }
    });
    orderInput.value = ids.join(',');
  }

  list.addEventListener('click', function(event) {
    if (event.target.classList.contains('img-delete')) {
      var imageId = event.target.getAttribute('data-image-id');
      if (!imageId) return;
      if (!confirm('¿Eliminar esta imagen?')) return;
      if (deleteInput) deleteInput.value = imageId;
      if (actionInput) actionInput.value = 'delete_image';
      if (form) form.submit();
      return;
    }
    if (event.target.classList.contains('move-up') || event.target.classList.contains('move-down')) {
      var item = event.target.closest('li');
      if (!item) return;
      if (event.target.classList.contains('move-up')) {
        var prev = item.previousElementSibling;
        if (prev && prev.hasAttribute('data-id')) {
          list.insertBefore(item, prev);
        }
      } else {
        var next = item.nextElementSibling;
        if (next) {
          list.insertBefore(next, item);
        }
      }
      updateOrder();
    }
  });

  updateOrder();
})();
</script><hr>";

echo "<h3>Stock y precio</h3>
<p>Precio actual (".h($priceSourceLabel)."): ".h($sellTxt)." (total: ".h((string)$stockTotal).")</p>
<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='action' value='update_stock'>
Own qty <input name='own_stock_qty' value='".h((string)$product['own_stock_qty'])."' style='width:70px'>
Own $ <input name='own_stock_price' value='".h((string)($product['own_stock_price']??''))."' style='width:90px'>
Manual $ <input name='manual_price' value='".h((string)($product['manual_price']??''))."' style='width:90px'>
<button>Guardar</button>
</form>";

page_footer();
