#!/usr/bin/env php
<?php

/**
 * Worker de campanhas de e-mail.
 * Uso: php worker/campanhas.php [id_admin] [limite]
 * Cron Linux: * * * * * php /caminho/painel-cti/worker/campanhas.php 0 1
 * (limite 1 por execução — alinhado ao pacing 1:1 do WhatsApp)
 */

require __DIR__.'/../includes/app.php';

use App\Common\Communication\CampanhaWorker;
use App\Model\Entity\CampanhaWorkerRun;
use App\Model\Entity\Campanhas;

if (!Campanhas::tabelaExiste()) {
	fwrite(STDERR, "Tabelas de campanha não existem.\n");
	exit(1);
}

$idAdmin = isset($argv[1]) ? (int)$argv[1] : 0;
$limite  = isset($argv[2]) ? (int)$argv[2] : 1;
if ($limite < 1 || $limite > 20) {
	$limite = 1;
}

$resumo = CampanhaWorker::processar($idAdmin, $limite, true);
CampanhaWorkerRun::registrar('cli', $idAdmin, $resumo);

echo json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
