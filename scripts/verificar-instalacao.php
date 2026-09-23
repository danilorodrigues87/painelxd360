<?php
/**
 * Diagnóstico rápido pós-deploy. Acesse uma vez e REMOVA do servidor.
 * https://app.xd360.com.br/scripts/verificar-instalacao.php
 */
header('Content-Type: text/plain; charset=utf-8');

$root = dirname(__DIR__);
$checks = [];

$checks[] = ['PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')];

$vendor = $root . '/vendor/autoload.php';
$checks[] = ['vendor/autoload.php', $vendor, is_file($vendor)];

$env = $root . '/.env';
$checks[] = ['.env', $env, is_file($env)];

if (is_file($env)) {
	$lines = file($env, FILE_IGNORE_NEW_LINES) ?: [];
	$keys = [];
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
			continue;
		}
		$k = trim(explode('=', $line, 2)[0]);
		$keys[$k] = ($keys[$k] ?? 0) + 1;
	}
	$dup = array_filter($keys, fn ($n) => $n > 1);
	$checks[] = ['.env chaves duplicadas', $dup ? implode(', ', array_keys($dup)) : 'nenhuma', empty($dup)];
	$checks[] = ['.env SITE', isset($keys['SITE']) ? 'ok' : 'faltando SITE=XD360', isset($keys['SITE'])];
	$checks[] = ['.env URL', isset($keys['URL']) ? 'ok' : 'faltando', isset($keys['URL'])];
}

if (is_file($vendor) && is_file($env)) {
	require $vendor;
	\App\Common\Environment::load($root);
	$host = getenv('DB_HOST') ?: 'localhost';
	$user = getenv('DB_USER') ?: '';
	$pass = getenv('DB_PASS') ?: '';
	$name = getenv('DB_NAME') ?: '';
	try {
		$pdo = new PDO(
			'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
			$user,
			$pass,
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		$checks[] = ['MySQL', $name . '@' . $host, true];
	} catch (Throwable $e) {
		$checks[] = ['MySQL', $e->getMessage(), false];
	}
}

$ht = $root . '/.htaccess';
$checks[] = ['.htaccess', $ht, is_file($ht)];

$sess = $root . '/app/sessions';
$checks[] = ['app/sessions gravável', $sess, is_dir($sess) && is_writable($sess)];

echo "XD360 — verificação de instalação\n";
echo str_repeat('=', 40) . "\n";
$ok = true;
foreach ($checks as [$label, $detail, $pass]) {
	$flag = $pass ? 'OK' : 'FALHA';
	if (!$pass) {
		$ok = false;
	}
	echo sprintf("[%s] %s — %s\n", $flag, $label, $detail);
}
echo str_repeat('=', 40) . "\n";
echo $ok ? "Resumo: pronto para testar /login\n" : "Corrija os itens FALHA (veja docs/TROUBLESHOOTING_CPANEL.md)\n";
