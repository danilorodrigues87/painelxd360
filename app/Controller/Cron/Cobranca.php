<?php

namespace App\Controller\Cron;

use App\Common\Communication\CobrancaEmailService;
use App\Common\Environment;
use App\Model\Entity\ComunicacaoWorkerRun;
use App\Model\Entity\EmailCobrancaLog;

/**
 * Disparo HTTP do worker de cobrança automática.
 * GET/POST /cron/cobranca?token={SYSTEM_TOKEN}
 */
class Cobranca {

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
			if (!EmailCobrancaLog::tabelaExiste()) {
				http_response_code(500);
				return json_encode(['success' => false, 'message' => 'Tabela email_cobranca_log ausente.']);
			}
			$idAdmin = (int)($q['id_admin'] ?? $post['id_admin'] ?? 0);
			$resumo = CobrancaEmailService::processar($idAdmin, false);
			ComunicacaoWorkerRun::registrar('cobranca', 'http', $idAdmin, $resumo);
			return json_encode(['success' => true, 'resumo' => $resumo], JSON_UNESCAPED_UNICODE);
		} catch (\Throwable $e) {
			http_response_code(500);
			return json_encode([
				'success' => false,
				'message' => 'Erro no worker de cobrança.',
			], JSON_UNESCAPED_UNICODE);
		}
	}
}
