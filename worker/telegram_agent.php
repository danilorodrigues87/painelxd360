#!/usr/bin/env php
<?php

/**
 * Worker long-poll do agente Telegram nativo.
 * Uso: php worker/telegram_agent.php [id_admin]
 * Cron (se sem HTTPS): * * * * * php /caminho/painel-cti/worker/telegram_agent.php
 *
 * Em produção com webhook HTTPS ativo, este worker não é necessário
 * (e getUpdates falha se o webhook estiver setado — use deleteWebhook antes).
 */

require __DIR__.'/../includes/app.php';

use App\Common\Communication\TelegramAgentService;

$idAdmin = isset($argv[1]) ? (int)$argv[1] : 0;

if ($idAdmin > 0) {
	$res = TelegramAgentService::processarPollEscola($idAdmin, 0);
	echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
	exit(!empty($res['ok']) ? 0 : 1);
}

$ids = TelegramAgentService::listarEscolasParaPoll();
$resumo = ['escolas' => count($ids), 'detalhe' => []];
foreach ($ids as $id) {
	$resumo['detalhe'][(string)$id] = TelegramAgentService::processarPollEscola($id, 0);
}

echo json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
