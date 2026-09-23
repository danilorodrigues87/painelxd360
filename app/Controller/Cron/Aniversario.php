<?php

namespace App\Controller\Cron;

use App\Common\Communication\AniversarioEmailService;
use App\Common\Environment;
use App\Model\Entity\ComunicacaoWorkerRun;
use App\Model\Entity\EmailAniversarioLog;
use App\Model\Entity\EscolaIntegracoes;

/**
 * Disparo HTTP do worker de aniversariantes.
 * GET/POST /cron/aniversario?token={SYSTEM_TOKEN}
 */
class Aniversario {

	public static function run($request) {
		header('Content-Type: application/json; charset=utf-8');
		try {
			$q = $request->getQueryParams() ?: [];
			$post = $request->getPostVars() ?: [];
			$token = (string)($q['token'] ?? $post['token'] ?? '');
			$expected = defined('SYSTEM_TOKEN') && SYSTEM_TOKEN !== ''
				? (string)SYSTEM_TOKEN
				: (string)Environment::get('SYSTEM_TOKEN', '');
			if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
				http_response_code(403);
				return json_encode(['success' => false, 'message' => 'Token inválido.']);
			}
			if (!EmailAniversarioLog::tabelaExiste() || !EscolaIntegracoes::temColunasAniversario()) {
				http_response_code(500);
				return json_encode(['success' => false, 'message' => 'Tabelas/colunas de aniversário ausentes.']);
			}
			$idAdmin = (int)($q['id_admin'] ?? $post['id_admin'] ?? 0);
			$resumo = AniversarioEmailService::processar($idAdmin, false);
			ComunicacaoWorkerRun::registrar('aniversario', 'http', $idAdmin, $resumo);
			return json_encode(['success' => true, 'resumo' => $resumo], JSON_UNESCAPED_UNICODE);
		} catch (\Throwable $e) {
			http_response_code(500);
			return json_encode([
				'success' => false,
				'message' => 'Erro no worker de aniversário.',
			], JSON_UNESCAPED_UNICODE);
		}
	}
}
