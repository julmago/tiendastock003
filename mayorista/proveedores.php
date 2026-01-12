<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('wholesaler','/mayorista/login.php');

$st = $pdo->prepare("SELECT id FROM wholesalers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$wholesaler = $st->fetch();
if (!$wholesaler) exit('Mayorista inválido');

// Cambio clave: vínculo mayorista -> proveedor (1 a N).
if (($_POST['action'] ?? '') === 'unlink') {
  $providerId = (int)($_POST['provider_id'] ?? 0);
  if ($providerId) {
    $pdo->prepare("DELETE FROM wholesaler_provider WHERE wholesaler_id=? AND provider_id=?")
        ->execute([(int)$wholesaler['id'], $providerId]);
    $msg = 'Proveedor desvinculado.';
  }
} elseif ($_SERVER['REQUEST_METHOD']==='POST') {
  $providerId = (int)($_POST['provider_id'] ?? 0);
  if (!$providerId) {
    $err = 'Elegí un proveedor válido.';
  } else {
    $pdo->prepare("INSERT IGNORE INTO wholesaler_provider(wholesaler_id, provider_id) VALUES(?,?)")
        ->execute([(int)$wholesaler['id'], $providerId]);
    $msg = 'Proveedor vinculado.';
  }
}

$providers = $pdo->query("SELECT id, display_name FROM providers WHERE status='active' ORDER BY id DESC")->fetchAll();
$linked = $pdo->prepare("SELECT p.id, p.display_name
  FROM wholesaler_provider wp
  JOIN providers p ON p.id=wp.provider_id
  WHERE wp.wholesaler_id=?");
$linked->execute([(int)$wholesaler['id']]);
$linkedProviders = $linked->fetchAll();

page_header('Vincular proveedores');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Proveedor:
<select name='provider_id'>
<option value='0'>-- elegir --</option>";
foreach ($providers as $p) {
  echo "<option value='".h((string)$p['id'])."'>".h($p['display_name'])."</option>";
}

echo "</select></p>
<button>Vincular</button>
</form><hr>";

echo "<h3>Proveedores vinculados</h3>";
if (!$linkedProviders) {
  echo "<p>No hay proveedores vinculados.</p>";
} else {
  echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>ID</th><th>Nombre</th><th></th></tr>";
  foreach ($linkedProviders as $p) {
    echo "<tr>
      <td>".h((string)$p['id'])."</td>
      <td>".h($p['display_name'])."</td>
      <td>
        <form method='post' style='margin:0' onsubmit='return confirm(\"¿Desvincular?\")'>
          <input type='hidden' name='csrf' value='".h(csrf_token())."'>
          <input type='hidden' name='action' value='unlink'>
          <input type='hidden' name='provider_id' value='".h((string)$p['id'])."'>
          <button>Quitar</button>
        </form>
      </td>
    </tr>";
  }
  echo "</table>";
}
page_footer();
