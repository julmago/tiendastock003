<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('provider','/proveedor/login.php');

$max_image_size_bytes = 10 * 1024 * 1024;
$image_sizes = [1200, 600, 150];

function product_image_with_size(string $base, int $size): string {
  $dot = strrpos($base, '.');
  if ($dot === false) {
    return $base.'_'.$size;
  }
  return substr($base, 0, $dot).'_'.$size.substr($base, $dot);
}

function prepare_png_canvas($image, int $width, int $height): void {
  imagealphablending($image, false);
  imagesavealpha($image, true);
  $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
  imagefilledrectangle($image, 0, 0, $width, $height, $transparent);
}

function save_resized_square($square, int $size, string $dest, int $image_type): bool {
  $resized = imagecreatetruecolor($size, $size);
  if ($image_type === IMAGETYPE_PNG) {
    prepare_png_canvas($resized, $size, $size);
  }
  $square_size = imagesx($square);
  imagecopyresampled($resized, $square, 0, 0, 0, 0, $size, $size, $square_size, $square_size);
  if ($image_type === IMAGETYPE_PNG) {
    $result = imagepng($resized, $dest, 6);
  } else {
    $result = imagejpeg($resized, $dest, 85);
  }
  imagedestroy($resized);
  return $result;
}

$st = $pdo->prepare("SELECT id, status FROM providers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$p = $st->fetch();
if (!$p) exit('Proveedor inválido');

$edit_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit_product = null;
$product_images = [];
$image_errors = [];

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (($p['status'] ?? '') !== 'active') $err="Cuenta pendiente de aprobación.";
  else {
    $title = trim((string)($_POST['title'] ?? ''));
    $price = (float)($_POST['base_price'] ?? 0);
    $sku = trim((string)($_POST['sku'] ?? ''));
    $universalCode = trim((string)($_POST['universal_code'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    if (!$title || $price<=0) $err="Completá título y precio base.";
    elseif ($universalCode !== '' && !preg_match('/^\d{8,14}$/', $universalCode)) $err = "El código universal debe tener entre 8 y 14 números.";
    else {
      if ($product_id > 0) {
        $st = $pdo->prepare("SELECT id FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
        $st->execute([$product_id,(int)$p['id']]);
        if ($st->fetch()) {
          $pdo->prepare("UPDATE provider_products SET title=?, sku=?, universal_code=?, description=?, base_price=? WHERE id=? AND provider_id=?")
              ->execute([$title,$sku?:null,$universalCode?:null,$desc?:null,$price,$product_id,(int)$p['id']]);
          $msg="Actualizado.";
          $edit_id = $product_id;
        } else {
          $err="Producto inválido.";
        }
      } else {
        $pdo->prepare("INSERT INTO provider_products(provider_id,title,sku,universal_code,description,base_price,status) VALUES(?,?,?,?,?,?,'active')")
            ->execute([(int)$p['id'],$title,$sku?:null,$universalCode?:null,$desc?:null,$price]);
        $msg="Creado.";
        $product_id = (int)$pdo->lastInsertId();
        $edit_id = $product_id;
      }
    }
  }

  if (empty($err) && $product_id > 0) {
    $upload_dir = __DIR__.'/../uploads/products/'.$product_id;
    if (!is_dir($upload_dir)) {
      mkdir($upload_dir, 0775, true);
    }

    if (!empty($_FILES['images']['name'][0])) {
      if (!function_exists('imagecreatefromjpeg')) {
        $image_errors[] = 'GD no está disponible para procesar imágenes.';
      } else {
        $st = $pdo->prepare("SELECT COALESCE(MAX(position), 0) FROM product_images WHERE product_id=?");
        $st->execute([$product_id]);
        $next_position = (int)$st->fetchColumn();
        foreach ($_FILES['images']['name'] as $idx => $name) {
          $error = $_FILES['images']['error'][$idx] ?? UPLOAD_ERR_NO_FILE;
          if ($error !== UPLOAD_ERR_OK) {
            if ($error !== UPLOAD_ERR_NO_FILE) {
              $image_errors[] = "Error al subir {$name}.";
            }
            continue;
          }
          $tmp_path = $_FILES['images']['tmp_name'][$idx] ?? '';
          $size = (int)($_FILES['images']['size'][$idx] ?? 0);
          if ($size <= 0 || $size > $max_image_size_bytes) {
            $image_errors[] = "La imagen {$name} supera el tamaño permitido.";
            continue;
          }
          $info = getimagesize($tmp_path);
          if ($info === false) {
            $image_errors[] = "El archivo {$name} no es una imagen válida.";
            continue;
          }
          $image_type = $info[2];
          if (!in_array($image_type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            $image_errors[] = "Formato no soportado para {$name}.";
            continue;
          }

          $ext = $image_type === IMAGETYPE_PNG ? 'png' : 'jpg';
          $base_name = bin2hex(random_bytes(16)).'.'.$ext;
          if ($image_type === IMAGETYPE_PNG) {
            $source = imagecreatefrompng($tmp_path);
          } else {
            $source = imagecreatefromjpeg($tmp_path);
          }
          if (!$source) {
            $image_errors[] = "No se pudo procesar {$name}.";
            continue;
          }
          $width = imagesx($source);
          $height = imagesy($source);
          $side = min($width, $height);
          $src_x = (int)(($width - $side) / 2);
          $src_y = (int)(($height - $side) / 2);
          $square = imagecreatetruecolor($side, $side);
          if ($image_type === IMAGETYPE_PNG) {
            prepare_png_canvas($square, $side, $side);
          }
          imagecopyresampled($square, $source, 0, 0, $src_x, $src_y, $side, $side, $side, $side);
          imagedestroy($source);

          $saved = true;
          foreach ($image_sizes as $target_size) {
            $dest = $upload_dir.'/'.product_image_with_size($base_name, $target_size);
            if (!save_resized_square($square, $target_size, $dest, $image_type)) {
              $saved = false;
              break;
            }
          }
          imagedestroy($square);

          if (!$saved) {
            $image_errors[] = "No se pudo guardar {$name}.";
            continue;
          }
          $next_position++;
          $is_cover = $next_position === 1 ? 1 : 0;
          $pdo->prepare("INSERT INTO product_images(product_id, filename_base, position, is_cover) VALUES(?,?,?,?)")
              ->execute([$product_id, $base_name, $next_position, $is_cover]);
        }
      }
    }

    $images_order_raw = trim((string)($_POST['images_order'] ?? ''));
    if ($images_order_raw !== '') {
      $ids = array_filter(array_map('intval', explode(',', $images_order_raw)));
      $st = $pdo->prepare("SELECT id FROM product_images WHERE product_id=?");
      $st->execute([$product_id]);
      $existing_ids = $st->fetchAll(PDO::FETCH_COLUMN);
      $existing_ids = array_map('intval', $existing_ids);
      $ordered = [];
      foreach ($ids as $id) {
        if (in_array($id, $existing_ids, true) && !in_array($id, $ordered, true)) {
          $ordered[] = $id;
        }
      }
      foreach ($existing_ids as $id) {
        if (!in_array($id, $ordered, true)) {
          $ordered[] = $id;
        }
      }
      $pdo->beginTransaction();
      $position = 1;
      foreach ($ordered as $id) {
        $pdo->prepare("UPDATE product_images SET position=?, is_cover=? WHERE id=? AND product_id=?")
            ->execute([$position, $position === 1 ? 1 : 0, $id, $product_id]);
        $position++;
      }
      $pdo->commit();
    }
  }
}

if ($edit_id > 0) {
  $st = $pdo->prepare("SELECT id,title,sku,universal_code,description,base_price FROM provider_products WHERE id=? AND provider_id=? LIMIT 1");
  $st->execute([$edit_id,(int)$p['id']]);
  $edit_product = $st->fetch();
  if (!$edit_product) {
    $err = $err ?? "Producto inválido.";
    $edit_id = 0;
  }
}

if ($edit_id > 0) {
  $st = $pdo->prepare("SELECT id, filename_base, position FROM product_images WHERE product_id=? ORDER BY position ASC");
  $st->execute([$edit_id]);
  $product_images = $st->fetchAll();
}

$rows = $pdo->prepare("SELECT id,title,sku,universal_code,base_price FROM provider_products WHERE provider_id=? ORDER BY id DESC");
$rows->execute([(int)$p['id']]);
$list = $rows->fetchAll();

page_header('Proveedor - Catálogo base');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";
if (!empty($image_errors)) {
  echo "<p style='color:#b00'>".h(implode(' ', $image_errors))."</p>";
}
echo "<form method='post' enctype='multipart/form-data'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<input type='hidden' name='product_id' value='".h((string)($edit_product['id'] ?? ''))."'>
<p>Título: <input name='title' style='width:520px' value='".h($edit_product['title'] ?? '')."'></p>
<p>SKU: <input name='sku' style='width:220px' value='".h($edit_product['sku'] ?? '')."'></p>
<p>Código universal (8-14 dígitos): <input name='universal_code' style='width:220px' value='".h($edit_product['universal_code'] ?? '')."'></p>
<p>Precio base: <input name='base_price' style='width:160px' value='".h((string)($edit_product['base_price'] ?? ''))."'></p>
<p>Descripción:<br><textarea name='description' rows='3' style='width:90%'>".h($edit_product['description'] ?? '')."</textarea></p>
<fieldset>
<legend>Imágenes</legend>
<p><input type='file' name='images[]' multiple accept='image/*'></p>
<input type='hidden' name='images_order' id='images_order' value=''>
<ul id='images-list'>";
if ($edit_product && $product_images) {
  foreach ($product_images as $index => $image) {
    $thumb = product_image_with_size($image['filename_base'], 150);
    $thumb_url = "/uploads/products/".h((string)$edit_product['id'])."/".h($thumb);
    $cover_label = $index === 0 ? "Portada" : "";
    echo "<li data-id='".h((string)$image['id'])."'>
<img src='".$thumb_url."' alt='' width='80' height='80'>
 <span class='cover-label'>".h($cover_label)."</span>
 <button type='button' class='move-up'>↑</button>
 <button type='button' class='move-down'>↓</button>
</li>";
  }
} else {
  echo "<li>No hay imágenes cargadas.</li>";
}
echo "</ul>
</fieldset>
<button>".($edit_product ? "Guardar cambios" : "Crear")."</button>";
if ($edit_product) {
  echo " <a href='/proveedor/catalogo.php'>Cancelar edición</a>";
}
echo "
</form>
<script>
(function() {
  var list = document.getElementById('images-list');
  var orderInput = document.getElementById('images_order');
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
</script>
<hr>";

echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Título</th><th>SKU</th><th>Código universal</th><th>Base</th></tr>";
foreach($list as $r){
  $url = "/proveedor/catalogo.php?id=".h((string)$r['id']);
  echo "<tr><td>".h((string)$r['id'])."</td><td><a href='".$url."'>".h($r['title'])."</a></td><td>".h($r['sku']??'')."</td><td>".h($r['universal_code']??'')."</td><td>".h((string)$r['base_price'])."</td></tr>";
}
echo "</table>";
page_footer();
