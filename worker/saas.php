#!/usr/bin/env php
<?php

/**
 * Worker de assinatura SaaS (Master → escolas).
 * Gera fatura do mês (PIX CTI) e suspende após grace (5 dias).
 *
 * Uso: php worker/saas.php [id_admin]
 * Cron Linux (1x/dia às 7h): 0 7 * * * php /caminho/painel-cti/worker/saas.php
 */

require __DIR__.'/../includes/app.php';

use App\Common\Helpers\SaasAssinaturaService;
use App\Model\Entity\SaasFatura;

if (!SaasFatura::tabelaExiste()) {
	fwrite(STDERR, "Tabela saas_faturas não existe. Execute database/saas_assinatura.sql\n");
	exit(1);
}

$idAdmin = isset($argv[1]) ? (int)$argv[1] : 0;
$resumo = SaasAssinaturaService::processar($idAdmin > 0 ? $idAdmin : null);

echo json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
