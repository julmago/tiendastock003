<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require_role('retailer','/minorista/login.php');

$st = $pdo->prepare("SELECT id, display_name, status FROM retailers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$retailer = $st->fetch();
if (!$retailer) exit('Minorista inválido');

page_header('Panel Minorista');
// Cambio clave: panel minorista separado del vendedor legado.
echo "<p>Hola, <b>".h($retailer['display_name'])."</b> (".h($retailer['status']).")</p>
<ul>
<li><a href='/minorista/tienda.php'>Mi tienda minorista</a></li>
<li><a href='/minorista/productos.php'>Productos</a></li>
<li><a href='/minorista/mayorista.php'>Vincular mayorista</a></li>
</ul>";
page_footer();
