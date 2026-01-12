<?php
require __DIR__.'/../config.php';
require __DIR__.'/../_inc/layout.php';

page_header('Panel Vendedor');
echo "<p>Este panel fue reemplazado por cuentas separadas.</p>
<ul>
  <li><a href='/mayorista/login.php'>Login Mayorista</a></li>
  <li><a href='/minorista/login.php'>Login Minorista</a></li>
</ul>";
page_footer();
