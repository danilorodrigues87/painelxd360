<?php

namespace App\Controller\Api\Editor;

use App\Model\Entity\EscolaIntegracoes;

/**
 * TTS OpenAI para narração do L-Editor.
 * Body JSON: { text, voice? } → audio/mpeg
 */
class Tts {

	private static function err(string $msg, int $code = 400): array {
		return [
			'code' => $code,
			'json' => json_encode(['message' => $msg], JSON_UNESCAPED_UNICODE),
		];
	}

	public static function gerar($request): array {
		$idAdmin = (int)($request->editorIdAdmin ?? 0);
		if ($idAdmin <= 0) {
			return self::err('Tenant inválido.', 403);
		}

		$post = $request->getPostVars();
		if (!is_array($post) || empty($post)) {
			$raw = file_get_contents('php://input');
			$decoded = is_string($raw) ? json_decode($raw, true) : null;
			$post = is_array($decoded) ? $decoded : [];
		}

		$text = trim((string)($post['text'] ?? ''));
		if ($text === '') {
			return self::err('Informe o texto.');
		}
		if (mb_strlen($text) > 2000) {
			$text = mb_substr($text, 0, 2000);
		}

		$voice = trim((string)($post['voice'] ?? 'alloy'));
		$allowed = ['alloy', 'verse', 'sage', 'ballad', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
		if (!in_array($voice, $allowed, true)) {
			$voice = 'alloy';
		}

		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			return self::err('Configure a IA em Configurações → Configurações de IA.', 501);
		}
		$key = $cfg->getAiApiKeyDescriptografada();
		if ($key === null || $key === '') {
			return self::err('API key de IA ausente. Configure em Configurações de IA.', 501);
		}
		$provider = strtolower(trim((string)($cfg->ai_provider ?: 'openai')));
		if ($provider !== 'openai' && $provider !== 'outro') {
			return self::err('TTS requer provedor OpenAI (ou compatível OpenAI). Ajuste em Configurações de IA.', 501);
		}

		$body = json_encode([
			'model' => 'gpt-4o-mini-tts',
			'input' => $text,
			'voice' => $voice,
			'response_format' => 'mp3',
			'instructions' => 'Fale em português do Brasil, com sotaque brasileiro neutro, tom de professor calmo, claro e didático, em ritmo levemente pausado.',
		], JSON_UNESCAPED_UNICODE);

		$t0 = microtime(true);
		$ch = curl_init('https://api.openai.com/v1/audio/speech');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Authorization: Bearer '.$key,
			],
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_TIMEOUT => 60,
		]);
		$raw = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		$latencyMs = (int)round((microtime(true) - $t0) * 1000);

		if ($raw === false) {
			\App\Common\Helpers\AiUsageLogger::logTts($idAdmin, 'gpt-4o-mini-tts', 0, $latencyMs, mb_strlen($text), false, 'cURL: '.$err);
			return self::err('Falha de rede no TTS: '.$err, 502);
		}
		if ($code >= 400) {
			$snip = mb_substr((string)$raw, 0, 200);
			\App\Common\Helpers\AiUsageLogger::logTts($idAdmin, 'gpt-4o-mini-tts', $code, $latencyMs, mb_strlen($text), false, $snip);
			return self::err('TTS HTTP '.$code.': '.$snip, 502);
		}

		\App\Common\Helpers\AiUsageLogger::logTts($idAdmin, 'gpt-4o-mini-tts', $code, $latencyMs, mb_strlen($text), true);

		return [
			'code' => 200,
			'contentType' => 'audio/mpeg',
			'json' => $raw, // Response body binário (nome legado do helper)
			'binary' => true,
		];
	}
}
