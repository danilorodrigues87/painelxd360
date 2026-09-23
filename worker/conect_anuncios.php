#!/usr/bin/env php
<?php

/**
 * Worker de assinatura de anúncios Conecta Jovem.
 * Gera faturas mensais e expira assinaturas após grace.
 *
 * Uso: php worker/conect_anuncios.php [id_empresa]
 * Cron (1x/dia): 0 8 * * * php /caminho/painel-cti/worker/conect_anuncios.php
 */

require __DIR__.'/../includes/app.php';

use App\Common\Helpers\ConectAnuncioAssinaturaService;

$idEmpresa = isset($argv[1]) ? (int)$argv[1] : 0;
$resumo = ConectAnuncioAssinaturaService::processar($idEmpresa > 0 ? $idEmpresa : null);

echo json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
