#!/usr/bin/env php
<?php

/**
 * Diagnóstico operacional de e-mail (não envia nada).
 * Uso: php worker/status-email.php [id_admin]
 */

require __DIR__.'/../includes/app.php';

use App\Common\Environment;
use App\Common\Communication\Email;
use App\Model\Entity\Campanhas;
use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\EmailCobrancaLog;
use App\Model\Entity\EmailAniversarioLog;
use App\Model\Entity\ComunicacaoWorkerRun;

$idAdmin = isset($argv[1]) ? (int)$argv[1] : 0;

$checks = [];

$checks['php'] = [
	'ok' => true,
	'version' => PHP_VERSION,
];

$checks['extensoes'] = [
	'openssl' => extension_loaded('openssl'),
	'pdo_mysql' => extension_loaded('pdo_mysql'),
	'mbstring' => extension_loaded('mbstring'),
];

$checks['env'] = [
	'APP_KEY' => Environment::get('APP_KEY') !== null && Environment::get('APP_KEY') !== '',
	'SYSTEM_TOKEN' => Environment::get('SYSTEM_TOKEN') !== null && Environment::get('SYSTEM_TOKEN') !== '',
	'SMTP_HOST' => (string)Environment::get('SMTP_HOST', '') !== '',
	'SMTP_FROM_EMAIL' => (string)Environment::get('SMTP_FROM_EMAIL', '') !== '',
	'SMTP_USER' => (string)Environment::get('SMTP_USER', '') !== '',
];

$sistema = Email::getRemetenteSistema();
$checks['email_sistema'] = [
	'from_email' => $sistema['email'] ?? '',
	'from_name' => $sistema['nome'] ?? '',
	'configurado' => !empty($sistema['email']),
];

$checks['tabelas'] = [
	'escola_integracoes' => EscolaIntegracoes::tabelaExiste(),
	'campanhas' => Campanhas::tabelaExiste(),
	'email_cobranca_log' => EmailCobrancaLog::tabelaExiste(),
	'email_aniversario_log' => EmailAniversarioLog::tabelaExiste(),
	'comunicacao_worker_runs' => ComunicacaoWorkerRun::tabelaExiste(),
	'colunas_cobranca' => EscolaIntegracoes::temColunasCobranca(),
	'colunas_aniversario' => EscolaIntegracoes::temColunasAniversario(),
];

if ($idAdmin > 0) {
	$int = EscolaIntegracoes::getByIdAdmin($idAdmin);
	$checks['escola'] = [
		'id_admin' => $idAdmin,
		'smtp_ativo' => $int instanceof EscolaIntegracoes ? (int)$int->smtp_ativo : 0,
		'smtp_configurado' => $int instanceof EscolaIntegracoes && $int->temSmtpConfigurado(),
		'cobranca_ativo' => $int instanceof EscolaIntegracoes ? (int)($int->cobranca_ativo ?? 0) : 0,
		'enviados_cobranca_hoje' => EmailCobrancaLog::contarHoje($idAdmin),
		'enviados_aniversario_hoje' => EmailAniversarioLog::contarHoje($idAdmin),
		'ultima_cobranca' => ComunicacaoWorkerRun::ultima('cobranca', $idAdmin),
		'ultima_aniversario' => ComunicacaoWorkerRun::ultima('aniversario', $idAdmin),
	];
}

$faltando = [];
foreach ($checks['tabelas'] as $nome => $ok) {
	if (!$ok) {
		$faltando[] = $nome;
	}
}

$avisos = [];
if (empty($checks['env']['APP_KEY'])) {
	$avisos[] = 'APP_KEY vazia no .env — senha SMTP usa fallback SYSTEM_TOKEN. Defina APP_KEY estável antes de produção.';
}
if (empty($checks['env']['SMTP_HOST']) || empty($checks['env']['SMTP_FROM_EMAIL'])) {
	$avisos[] = 'SMTP do sistema incompleto no .env (recovery/fallback).';
}

$checks['resumo'] = [
	'pronto_para_workers' => empty($faltando),
	'tabelas_faltando' => $faltando,
	'avisos' => $avisos,
	'dica' => empty($faltando)
		? 'Tabelas OK. Configure cron e teste Simular hoje no painel.'
		: 'Execute o SQL pendente no phpMyAdmin (ver docs/OPERACAO_EMAIL.md).',
];

echo json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
exit(empty($faltando) ? 0 : 1);
