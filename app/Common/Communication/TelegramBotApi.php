<?php

namespace App\Common\Communication;

use App\Common\Environment;

/**
 * Cliente mínimo da Telegram Bot API.
 */
class TelegramBotApi {

	private string $token;

	public function __construct(string $token) {
		$this->token = trim($token);
	}

	public static function webhookToken(int $idAdmin): string {
		$secret = Environment::get('APP_KEY') ?: Environment::get('SYSTEM_TOKEN') ?: 'painel-cti';
		return hash_hmac('sha256', 'telegram-webhook-'.(int)$idAdmin, (string)$secret);
	}

	public static function webhookUrl(int $idAdmin): string {
		$base = rtrim((string)(defined('URL') ? URL : ''), '/');
		$token = self::webhookToken($idAdmin);
		return $base.'/webhook/telegram/'.(int)$idAdmin.'/'.rawurlencode($token);
	}

	public function getMe(): array {
		return $this->call('getMe');
	}

	public function sendMessage(string $chatId, string $text, array $extra = []): array {
		$payload = array_merge([
			'chat_id' => $chatId,
			'text' => mb_substr($text, 0, 4000),
			'disable_web_page_preview' => true,
		], $extra);
		return $this->call('sendMessage', $payload);
	}

	public function setWebhook(string $url): array {
		return $this->call('setWebhook', [
			'url' => $url,
			'allowed_updates' => json_encode(['message']),
			'drop_pending_updates' => false,
		]);
	}

	public function deleteWebhook(bool $dropPending = false): array {
		return $this->call('deleteWebhook', [
			'drop_pending_updates' => $dropPending ? true : false,
		]);
	}

	public function getWebhookInfo(): array {
		return $this->call('getWebhookInfo');
	}

	/**
	 * @return array{ok:bool,result?:array,description?:string}
	 */
	public function getUpdates(int $offset = 0, int $timeout = 0, int $limit = 20): array {
		return $this->call('getUpdates', [
			'offset' => $offset,
			'timeout' => max(0, min(50, $timeout)),
			'limit' => max(1, min(100, $limit)),
			'allowed_updates' => json_encode(['message']),
		]);
	}

	private function call(string $method, array $params = []): array {
		if ($this->token === '') {
			return ['ok' => false, 'description' => 'Token vazio.'];
		}
		$url = 'https://api.telegram.org/bot'.$this->token.'/'.$method;
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $params,
			CURLOPT_TIMEOUT => 55,
			CURLOPT_CONNECTTIMEOUT => 10,
		]);
		$raw = curl_exec($ch);
		$err = curl_error($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($raw === false) {
			return ['ok' => false, 'description' => 'cURL: '.$err];
		}
		$data = json_decode((string)$raw, true);
		if (!is_array($data)) {
			return ['ok' => false, 'description' => 'Resposta inválida HTTP '.$code];
		}
		if (!isset($data['ok'])) {
			$data['ok'] = $code >= 200 && $code < 300;
		}
		return $data;
	}
}
