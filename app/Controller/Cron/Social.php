<?php

namespace App\Controller\Cron;

use App\Common\Helpers\SocialPublishService;
use App\Model\Entity\SocialPost;

/**
 * Disparo HTTP do worker social (cPanel / cron URL).
 * GET/POST /cron/social?token={SYSTEM_TOKEN}
 */
class Social {

	public static function run($request) {
		header('Content-Type: application/json; charset=utf-8');
		$q = $request->getQueryParams() ?: [];
		$post = $request->getPostVars() ?: [];
		$token = (string)($q['token'] ?? $post['token'] ?? '');
		$expected = defined('SYSTEM_TOKEN') ? (string)SYSTEM_TOKEN : (string)(getenv('SYSTEM_TOKEN') ?: '');
		if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
			http_response_code(403);
			return json_encode(['success' => false, 'message' => 'Token inválido.']);
		}
		if (!SocialPost::tabelaExiste()) {
			http_response_code(500);
			return json_encode(['success' => false, 'message' => 'Tabela social_posts ausente.']);
		}
		$idAdmin = (int)($q['id_admin'] ?? $post['id_admin'] ?? 0);
		$limite = (int)($q['limite'] ?? $post['limite'] ?? 15);
		if ($limite < 1 || $limite > 50) {
			$limite = 15;
		}
		$resumo = SocialPublishService::processar($idAdmin, $limite, 'cron');
		return json_encode(['success' => true, 'resumo' => $resumo], JSON_UNESCAPED_UNICODE);
	}
}
