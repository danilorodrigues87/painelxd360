<?php

namespace App\Controller\Webhook;

use App\Common\Helpers\MetaGraphHelper;
use App\Common\Helpers\SocialAutomacaoService;
use App\Common\Helpers\MetaMessagingService;
use App\Common\Helpers\MetaWebhookDebug;
use App\Model\Entity\EscolaIntegracoes;

/**
 * Webhook Meta Graph.
 * GET: verificação hub.challenge
 * POST: comentários → keyword → DM | messaging → inbox Meta
 */
class Meta {

	/** Verify global (App) — GET /webhook/meta */
	public static function verifyGlobal($request) {
		$q = $request->getQueryParams() ?: [];
		return self::hubChallenge($q);
	}

	/**
	 * POST global — Meta App Dashboard costuma usar uma única Callback URL.
	 * Resolve a escola pelo Page ID / IG User ID do payload.
	 */
	public static function receberGlobal($request) {
		$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
		if ($method === 'GET') {
			return self::hubChallenge($request->getQueryParams() ?: []);
		}

		$raw = file_get_contents('php://input');
		if (!self::validarAssinatura((string)$raw)) {
			MetaWebhookDebug::logEvento('global/assinatura_invalida', 'POST rejeitado — assinatura inválida ou ausente');
			http_response_code(403);
			return json_encode(['success' => false, 'message' => 'Assinatura inválida.']);
		}

		$payload = json_decode((string)$raw, true);
		$resumo = ['comentarios' => ['processados' => 0], 'messaging' => ['processados' => 0]];
		if (is_array($payload)) {
			MetaWebhookDebug::logInbound(null, $payload, 'global');
			$resumo['comentarios'] = SocialAutomacaoService::processarPayload(null, $payload);
			$resumo['messaging'] = MetaMessagingService::processarPayload(null, $payload);
		}

		return json_encode(['success' => true, 'resumo' => $resumo], JSON_UNESCAPED_UNICODE);
	}

	/** GET/POST /webhook/meta/{idAdmin}/{token} */
	public static function receber($request, $idAdmin, $token) {
		$idAdmin = (int)$idAdmin;
		$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

		if ($method === 'GET') {
			$q = $request->getQueryParams() ?: [];
			return self::hubChallenge($q);
		}

		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		$esperado = ($cfg instanceof EscolaIntegracoes) ? (string)($cfg->meta_webhook_token ?? '') : '';
		if ($esperado === '' || !hash_equals($esperado, (string)$token)) {
			http_response_code(403);
			return json_encode(['success' => false, 'message' => 'Token inválido.']);
		}

		$raw = file_get_contents('php://input');
		if (!self::validarAssinatura((string)$raw)) {
			MetaWebhookDebug::logEvento('escola/assinatura_invalida', 'POST rejeitado — assinatura inválida ou ausente');
			http_response_code(403);
			return json_encode(['success' => false, 'message' => 'Assinatura inválida.']);
		}

		$payload = json_decode((string)$raw, true);
		$resumo = ['comentarios' => ['processados' => 0], 'messaging' => ['processados' => 0]];
		if (is_array($payload)) {
			MetaWebhookDebug::logInbound($idAdmin, $payload, 'escola');
			$resumo['comentarios'] = SocialAutomacaoService::processarPayload($idAdmin, $payload);
			$resumo['messaging'] = MetaMessagingService::processarPayload($idAdmin, $payload);
		}

		return json_encode(['success' => true, 'resumo' => $resumo], JSON_UNESCAPED_UNICODE);
	}

	private static function validarAssinatura(string $raw): bool {
		$sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
		$secret = MetaGraphHelper::appSecret();
		// Sem secret ou sem header: em produção o ideal é falhar; mantém compat Dev
		if ($secret === '') {
			return true;
		}
		if ($sig === '') {
			return false;
		}
		$expected = 'sha256='.hash_hmac('sha256', $raw, $secret);
		return hash_equals($expected, $sig);
	}

	private static function hubChallenge(array $q) {
		$mode = (string)($q['hub_mode'] ?? $q['hub.mode'] ?? '');
		$token = (string)($q['hub_verify_token'] ?? $q['hub.verify_token'] ?? '');
		$challenge = (string)($q['hub_challenge'] ?? $q['hub.challenge'] ?? '');
		$expected = MetaGraphHelper::webhookVerifyToken();
		if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
			header('Content-Type: text/plain');
			return $challenge;
		}
		http_response_code(403);
		return 'Forbidden';
	}
}
