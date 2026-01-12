<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';
// Cambio clave: acceso vendedor deprecado, reemplazado por roles mayorista/minorista.
page_header('Acceso Vendedor');
echo "<p>El acceso de vendedor fue reemplazado por cuentas separadas.</p>
<ul>
  <li><a href='/mayorista/login.php'>Login Mayorista</a></li>
  <li><a href='/minorista/login.php'>Login Minorista</a></li>
</ul>";
page_footer();
