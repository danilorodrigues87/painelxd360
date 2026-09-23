<?php

namespace App\Controller\Webhook;

use App\Common\Communication\TelegramBotApi;
use App\Common\Communication\TelegramAgentService;

class Telegram {

	public static function receber($request, $idAdmin, $token) {
		$idAdmin = (int)$idAdmin;
		$esperado = TelegramBotApi::webhookToken($idAdmin);
		if (!hash_equals($esperado, (string)$token)) {
			return json_encode(['ok' => false, 'message' => 'Token inválido.']);
		}

		$raw = file_get_contents('php://input');
		$payload = json_decode((string)$raw, true);
		if (!is_array($payload)) {
			$post = $request->getPostVars();
			$payload = is_array($post) ? $post : [];
		}

		@set_time_limit(90);
		ignore_user_abort(true);

		try {
			TelegramAgentService::processarUpdate($idAdmin, $payload);
		} catch (\Throwable $e) {
			error_log('[TelegramWebhook] id_admin='.$idAdmin.' '.$e->getMessage());
		}

		return json_encode(['ok' => true]);
	}

	public static function ping($idAdmin, $token) {
		$idAdmin = (int)$idAdmin;
		$esperado = TelegramBotApi::webhookToken($idAdmin);
		if (!hash_equals($esperado, (string)$token)) {
			return json_encode(['ok' => false, 'message' => 'Token inválido.']);
		}
		return json_encode(['ok' => true, 'service' => 'telegram-agent']);
	}
}
