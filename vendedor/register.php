<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';

// Cambio clave: registro vendedor deprecado, reemplazado por mayorista/minorista.
page_header('Registro Vendedor');
echo "<p>El registro de vendedor fue reemplazado por cuentas separadas.</p>
<ul>
  <li><a href='/mayorista/register.php'>Registro Mayorista</a></li>
  <li><a href='/minorista/register.php'>Registro Minorista</a></li>
</ul>";
page_footer();
