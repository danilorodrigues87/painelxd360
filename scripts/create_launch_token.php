<?php
require __DIR__ . '/../includes/app.php';

use App\Model\Entity\ProdutoLaunchToken;

$tenantId = (int)($argv[1] ?? 1);
$userId = (int)($argv[2] ?? 2);
$slug = $argv[3] ?? 'doceflow';

echo ProdutoLaunchToken::criar($tenantId, $userId, $slug) . PHP_EOL;
