<?php

namespace App\Common\Helpers;

use App\Model\Entity\AiUsageLog;

class AiUsageLogger {

	public static function log(array $data): void {
		AiUsageLog::registrar($data);
	}

	public static function logFromOpenAiResponse(
		int $idAdmin,
		string $feature,
		string $provider,
		string $model,
		int $httpStatus,
		int $latencyMs,
		?array $data,
		bool $textOk,
		?string $errorSnippet = null
	): void {
		$usage = is_array($data) ? ($data['usage'] ?? []) : [];
		self::log([
			'id_admin'          => $idAdmin,
			'feature'           => $feature,
			'provider'          => $provider,
			'model'             => $model,
			'prompt_tokens'     => (int)($usage['prompt_tokens'] ?? 0),
			'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
			'total_tokens'      => (int)($usage['total_tokens'] ?? 0),
			'success'           => $textOk && $httpStatus > 0 && $httpStatus < 400,
			'http_status'       => $httpStatus,
			'latency_ms'        => $latencyMs,
			'error_snippet'     => $errorSnippet,
		]);
	}

	public static function logFromGeminiResponse(
		int $idAdmin,
		string $feature,
		string $model,
		int $httpStatus,
		int $latencyMs,
		?array $data,
		bool $textOk,
		?string $errorSnippet = null
	): void {
		$meta = is_array($data) ? ($data['usageMetadata'] ?? []) : [];
		$prompt = (int)($meta['promptTokenCount'] ?? 0);
		$completion = (int)($meta['candidatesTokenCount'] ?? 0);
		$total = (int)($meta['totalTokenCount'] ?? ($prompt + $completion));
		self::log([
			'id_admin'          => $idAdmin,
			'feature'           => $feature,
			'provider'          => 'gemini',
			'model'             => $model,
			'prompt_tokens'     => $prompt,
			'completion_tokens' => $completion,
			'total_tokens'      => $total,
			'success'           => $textOk && $httpStatus > 0 && $httpStatus < 400,
			'http_status'       => $httpStatus,
			'latency_ms'        => $latencyMs,
			'error_snippet'     => $errorSnippet,
		]);
	}

	public static function logTts(
		int $idAdmin,
		string $model,
		int $httpStatus,
		int $latencyMs,
		int $charsIn,
		bool $success,
		?string $errorSnippet = null
	): void {
		self::log([
			'id_admin'      => $idAdmin,
			'feature'       => 'tts',
			'provider'      => 'openai',
			'model'         => $model,
			'chars_in'      => $charsIn,
			'success'       => $success,
			'http_status'   => $httpStatus,
			'latency_ms'    => $latencyMs,
			'error_snippet' => $errorSnippet,
		]);
	}

	public static function labelFeature(string $feature): string {
		$labels = [
			'tutor'              => 'Tutor EAD',
			'roleplay'           => 'Role play',
			'roleplay_eval'      => 'Avaliação role play',
			'essay'              => 'Correção dissertativa',
			'telegram'           => 'Assistente Telegram',
			'whatsapp_variacao'  => 'WhatsApp (variação)',
			'tts'                => 'Narração TTS',
			'chat'               => 'Chat genérico',
		];
		return $labels[$feature] ?? $feature;
	}
}
