#!/usr/bin/env php
<?php

/**
 * Snapshot diário de ranking LMS (escola + global) para conquistas de posição × dias.
 *
 * Uso: php worker/lms_ranking_diario.php [YYYY-MM-DD]
 * Cron (1×/dia): 15 0 * * * php /caminho/painel-cti/worker/lms_ranking_diario.php
 */

require __DIR__.'/../includes/app.php';

use App\Model\Entity\LmsRankingDiario;
use App\Model\Entity\LmsXpLedger;
use App\Model\Db\Database;
use App\Common\Helpers\LmsXpHelper;

if (!LmsRankingDiario::tabelaExiste()) {
	fwrite(STDERR, "Tabela lms_ranking_diario ausente. Execute database/lms_conquistas_v3.sql\n");
	exit(1);
}
if (!LmsXpHelper::tabelasExistem()) {
	fwrite(STDERR, "Tabela lms_xp_ledger ausente.\n");
	exit(1);
}

$data = isset($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])
	? $argv[1]
	: date('Y-m-d');

$resumo = ['data' => $data, 'escolas' => 0, 'linhas_escola' => 0, 'linhas_global' => 0];

$admins = [];
try {
	$stmt = (new Database('lms_xp_ledger'))->execute(
		'SELECT DISTINCT id_admin FROM lms_xp_ledger WHERE id_admin > 0'
	);
	while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
		$admins[] = (int)$r['id_admin'];
	}
} catch (\Throwable $e) {
	fwrite(STDERR, "Falha ao listar escolas: ".$e->getMessage()."\n");
	exit(1);
}

foreach ($admins as $idAdmin) {
	$rows = LmsXpLedger::rankingByAdmin($idAdmin, 100);
	$pos = 0;
	foreach ($rows as $r) {
		$pos++;
		LmsRankingDiario::upsert(
			$data,
			'escola',
			$idAdmin,
			(int)$r['id_aluno'],
			$pos,
			(int)$r['xp_total']
		);
		$resumo['linhas_escola']++;
	}
	$resumo['escolas']++;
}

$global = LmsXpLedger::rankingGlobalPeriodo(30, 200);
$pos = 0;
foreach ($global as $r) {
	$pos++;
	LmsRankingDiario::upsert(
		$data,
		'global',
		0,
		(int)$r['id_aluno'],
		$pos,
		(int)$r['xp_total']
	);
	$resumo['linhas_global']++;
}

echo json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
