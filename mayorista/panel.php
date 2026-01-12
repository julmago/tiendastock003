<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
require_role('wholesaler','/mayorista/login.php');

$st = $pdo->prepare("SELECT id, display_name, status FROM wholesalers WHERE user_id=? LIMIT 1");
$st->execute([(int)$_SESSION['uid']]);
$wholesaler = $st->fetch();
if (!$wholesaler) exit('Mayorista inválido');

page_header('Panel Mayorista');
// Cambio clave: panel mayorista separado del vendedor legado.
echo "<p>Hola, <b>".h($wholesaler['display_name'])."</b> (".h($wholesaler['status']).")</p>
<ul>
<li><a href='/mayorista/tienda.php'>Mi tienda mayorista</a></li>
<li><a href='/mayorista/productos.php'>Productos</a></li>
<li><a href='/mayorista/proveedores.php'>Vincular proveedores</a></li>
</ul>";
page_footer();
