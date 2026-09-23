#!/usr/bin/env php
<?php

/**
 * Worker de cobrança automática por e-mail.
 * Uso: php worker/cobranca.php [id_admin]
 * Cron Linux (1x/dia às 8h): 0 8 * * * php /caminho/painel-cti/worker/cobranca.php
 */

require __DIR__.'/../includes/app.php';

use App\Common\Communication\CobrancaEmailService;
use App\Model\Entity\ComunicacaoWorkerRun;
use App\Model\Entity\EmailCobrancaLog;

if (!EmailCobrancaLog::tabelaExiste()) {
	fwrite(STDERR, "Tabela email_cobranca_log não existe.\n");
	exit(1);
}

$idAdmin = isset($argv[1]) ? (int)$argv[1] : 0;
$resumo = CobrancaEmailService::processar($idAdmin, false);
ComunicacaoWorkerRun::registrar('cobranca', 'cli', $idAdmin, $resumo);

echo json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
