<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
csrf_check();
require_role('retailer','/minorista/login.php');

$st = $pdo->prepare("SELECT id FROM retailers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$retailer = $st->fetch();
if (!$retailer) exit('Minorista inválido');

// Cambio clave: vínculo minorista -> mayorista (1 a 1).
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $wholesalerId = (int)($_POST['wholesaler_id'] ?? 0);
  if (!$wholesalerId) {
    $err = 'Elegí un mayorista válido.';
  } else {
    $pdo->prepare("INSERT INTO retailer_wholesaler(retailer_id, wholesaler_id)
                   VALUES(?,?)
                   ON DUPLICATE KEY UPDATE wholesaler_id=VALUES(wholesaler_id)")
        ->execute([(int)$retailer['id'], $wholesalerId]);
    $msg = 'Mayorista vinculado.';
  }
}

$currentSt = $pdo->prepare("SELECT rw.wholesaler_id, w.display_name
  FROM retailer_wholesaler rw
  JOIN wholesalers w ON w.id=rw.wholesaler_id
  WHERE rw.retailer_id=? LIMIT 1");
$currentSt->execute([(int)$retailer['id']]);
$current = $currentSt->fetch();

$wholesalers = $pdo->query("SELECT id, display_name FROM wholesalers WHERE status='active' ORDER BY id DESC")->fetchAll();

page_header('Vincular mayorista');
if (!empty($msg)) echo "<p style='color:green'>".h($msg)."</p>";
if (!empty($err)) echo "<p style='color:#b00'>".h($err)."</p>";

if ($current) {
  echo "<p>Mayorista actual: <b>".h($current['display_name'])."</b></p>";
} else {
  echo "<p>No tenés mayorista vinculado.</p>";
}

echo "<form method='post'>
<input type='hidden' name='csrf' value='".h(csrf_token())."'>
<p>Mayorista:
<select name='wholesaler_id'>
<option value='0'>-- elegir --</option>";
foreach ($wholesalers as $w) {
  $sel = ($current && (int)$current['wholesaler_id'] === (int)$w['id']) ? "selected" : "";
  echo "<option value='".h((string)$w['id'])."' {$sel}>".h($w['display_name'])."</option>";
}

echo "</select></p>
<button>Guardar vínculo</button>
</form>";
page_footer();
