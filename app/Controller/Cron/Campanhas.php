<?php

namespace App\Controller\Cron;

use App\Common\Communication\CampanhaWorker;
use App\Common\Environment;
use App\Model\Entity\CampanhaWorkerRun;
use App\Model\Entity\Campanhas as CampanhasEntity;

/**
 * Disparo HTTP do worker de campanhas (e-mail + WhatsApp).
 * GET/POST /cron/campanhas?token={SYSTEM_TOKEN}&limite=1
 *
 * HostGator / cPanel: a cada 1 min, sem depender de sessão no painel.
 */
class Campanhas {

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
			if (!CampanhasEntity::tabelaExiste()) {
				http_response_code(500);
				return json_encode(['success' => false, 'message' => 'Tabelas de campanha ausentes.']);
			}
			$idAdmin = (int)($q['id_admin'] ?? $post['id_admin'] ?? 0);
			$limite = (int)($q['limite'] ?? $post['limite'] ?? 1);
			if ($limite < 1 || $limite > 20) {
				$limite = 1;
			}
			$resumo = CampanhaWorker::processar($idAdmin, $limite, true);
			CampanhaWorkerRun::registrar('http', $idAdmin, $resumo);
			return json_encode(['success' => true, 'resumo' => $resumo], JSON_UNESCAPED_UNICODE);
		} catch (\Throwable $e) {
			http_response_code(500);
			return json_encode([
				'success' => false,
				'message' => 'Erro no worker de campanhas.',
			], JSON_UNESCAPED_UNICODE);
		}
	}
}
