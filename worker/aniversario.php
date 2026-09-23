#!/usr/bin/env php
<?php

/**
 * Worker de aniversariantes por e-mail.
 * Uso: php worker/aniversario.php [id_admin]
 * Cron Linux (1x/dia às 08:05): 5 8 * * * php /caminho/painel-cti/worker/aniversario.php
 */

require __DIR__.'/../includes/app.php';

use App\Common\Communication\AniversarioEmailService;
use App\Model\Entity\ComunicacaoWorkerRun;
use App\Model\Entity\EmailAniversarioLog;

if (!EmailAniversarioLog::tabelaExiste()) {
	fwrite(STDERR, "Tabela email_aniversario_log não existe.\n");
	exit(1);
}

$idAdmin = isset($argv[1]) ? (int)$argv[1] : 0;
$resumo = AniversarioEmailService::processar($idAdmin, false);
ComunicacaoWorkerRun::registrar('aniversario', 'cli', $idAdmin, $resumo);

echo json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
