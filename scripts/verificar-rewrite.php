<?php
/**
 * Teste rápido de rewrite. REMOVA após usar.
 * Acesse: https://app.xd360.com.br/scripts/verificar-rewrite.php
 */
header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);
$ht = $root . '/.htaccess';

echo "URI desta request: " . ($_SERVER['REQUEST_URI'] ?? '-') . "\n";
echo ".htaccess existe: " . (is_file($ht) ? 'sim' : 'NAO — copie do Git') . "\n";
if (function_exists('apache_get_modules')) {
	echo "mod_rewrite: " . (in_array('mod_rewrite', apache_get_modules(), true) ? 'sim' : 'nao listado') . "\n";
} else {
	echo "mod_rewrite: (nao da para detectar neste SAPI)\n";
}
echo "\nTeste manual:\n";
echo "  /login e /master devem abrir o XD360 (login), nao pagina 404 HostGator.\n";
echo "Se so / funciona, faca deploy do .htaccess e confira se nao existe pasta fisica 'master'.\n";
