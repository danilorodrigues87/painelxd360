<?php

namespace App\Common\Helpers;

use App\Model\Entity\EscolaIntegracoes;

/**
 * Proxy LLM por escola (OpenAI-compatible / Gemini). Stub se inativo ou API falhar.
 */
class LmsAiService {

	/** Último erro técnico (não expor ao aluno em produção). */
	private static ?string $lastError = null;

	public static function getLastError(): ?string {
		return self::$lastError;
	}

	public static function chat(int $idAdmin, array $messages, string $systemPrompt = '', string $feature = 'chat'): string {
		self::$lastError = null;
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes || !$cfg->temAiAtivo()) {
			self::$lastError = 'IA inativa ou sem chave.';
			return self::stubReply($messages, false);
		}
		$provider = (string)($cfg->ai_provider ?: 'openai');
		$key = $cfg->getAiApiKeyDescriptografada();
		$model = trim((string)($cfg->ai_model ?: ''));
		if ($model === '') {
			$model = $provider === 'gemini' ? 'gemini-2.0-flash' : 'gpt-4o-mini';
		}

		if ($provider === 'gemini') {
			$text = self::callGemini($idAdmin, $feature, $key, $model, $messages, $systemPrompt);
			if ($text !== null) {
				return $text;
			}
			// Fallback de modelo antigo → novo
			if (stripos($model, '1.5') !== false) {
				$text = self::callGemini($idAdmin, $feature, $key, 'gemini-2.0-flash', $messages, $systemPrompt);
				if ($text !== null) {
					return $text;
				}
			}
			return self::stubReply($messages, true);
		}
		$text = self::callOpenAiCompatible($idAdmin, $feature, $provider, $key, $model, $messages, $systemPrompt, null);
		return $text !== null ? $text : self::stubReply($messages, true);
	}

	/**
	 * Chat usando credencial salva (ignora toggle pedagógico ai_ativo).
	 * Retorna null se sem chave ou API falhou (sem stub).
	 */
	public static function chatComCredencial(int $idAdmin, array $messages, string $systemPrompt = '', string $feature = 'chat'): ?string {
		self::$lastError = null;
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			self::$lastError = 'Sem integração.';
			return null;
		}
		$key = $cfg->getAiApiKeyDescriptografada();
		if ($key === null || $key === '') {
			self::$lastError = 'Sem API key de IA.';
			return null;
		}
		$provider = (string)($cfg->ai_provider ?: 'openai');
		$model = trim((string)($cfg->ai_model ?: ''));
		if ($model === '') {
			$model = $provider === 'gemini' ? 'gemini-2.0-flash' : 'gpt-4o-mini';
		}

		if ($provider === 'gemini') {
			$text = self::callGemini($idAdmin, $feature, $key, $model, $messages, $systemPrompt);
			if ($text !== null) {
				return $text;
			}
			if (stripos($model, '1.5') !== false) {
				return self::callGemini($idAdmin, $feature, $key, 'gemini-2.0-flash', $messages, $systemPrompt);
			}
			return null;
		}
		return self::callOpenAiCompatible($idAdmin, $feature, $provider, $key, $model, $messages, $systemPrompt, null);
	}

	private static function stubReply(array $messages, bool $configuredButFailed): string {
		$last = '';
		foreach (array_reverse($messages) as $m) {
			if (($m['role'] ?? '') === 'user') {
				$last = (string)($m['content'] ?? '');
				break;
			}
		}
		$snip = mb_substr(trim($last), 0, 80);
		if ($configuredButFailed) {
			$hint = 'A chave de IA está salva, mas a chamada à API falhou'
				.(self::$lastError ? ' ('.self::$lastError.')' : '')
				.'. Verifique o modelo em Configurações → Configurações de IA (ex.: gemini-2.0-flash ou gpt-4o-mini).';
		} else {
			$hint = 'Configure a IA em Configurações → Configurações de IA (ativar + chave API).';
		}
		return "Entendi: \"{$snip}\".\n\n(Resposta simulada — {$hint})\n\nPode continuar; estou no personagem do exercício.";
	}

	/** @return string|null texto ou null se falhou */
	private static function callOpenAiCompatible(
		int $idAdmin,
		string $feature,
		string $provider,
		?string $key,
		string $model,
		array $messages,
		string $systemPrompt,
		?string $baseUrl = null
	): ?string {
		$url = ($baseUrl ?: 'https://api.openai.com/v1').'/chat/completions';
		$payloadMessages = [];
		if ($systemPrompt !== '') {
			$payloadMessages[] = ['role' => 'system', 'content' => $systemPrompt];
		}
		foreach ($messages as $m) {
			$role = $m['role'] === 'ai' ? 'assistant' : ($m['role'] ?? 'user');
			if ($role === 'assistant' || $role === 'user' || $role === 'system') {
				$payloadMessages[] = ['role' => $role, 'content' => (string)($m['content'] ?? '')];
			}
		}
		$body = json_encode([
			'model' => $model,
			'messages' => $payloadMessages,
			'temperature' => 0.7,
		], JSON_UNESCAPED_UNICODE);

		$t0 = microtime(true);
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Authorization: Bearer '.$key,
			],
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_TIMEOUT => 45,
		]);
		$raw = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		$latencyMs = (int)round((microtime(true) - $t0) * 1000);

		if ($raw === false) {
			self::$lastError = 'cURL: '.$err;
			AiUsageLogger::logFromOpenAiResponse($idAdmin, $feature, $provider, $model, 0, $latencyMs, null, false, self::$lastError);
			return null;
		}
		$data = json_decode($raw, true);
		if ($code >= 400) {
			self::$lastError = 'OpenAI HTTP '.$code.': '.mb_substr((string)$raw, 0, 120);
			AiUsageLogger::logFromOpenAiResponse($idAdmin, $feature, $provider, $model, $code, $latencyMs, is_array($data) ? $data : null, false, self::$lastError);
			return null;
		}
		$text = $data['choices'][0]['message']['content'] ?? null;
		$ok = is_string($text) && $text !== '';
		AiUsageLogger::logFromOpenAiResponse($idAdmin, $feature, $provider, $model, $code, $latencyMs, is_array($data) ? $data : null, $ok, $ok ? null : 'Resposta vazia');
		return $ok ? $text : null;
	}

	/** @return string|null */
	private static function callGemini(
		int $idAdmin,
		string $feature,
		?string $key,
		string $model,
		array $messages,
		string $systemPrompt
	): ?string {
		$model = preg_replace('#^models/#', '', $model);
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.urlencode((string)$key);

		$contents = [];
		foreach ($messages as $m) {
			$role = ($m['role'] ?? '') === 'ai' || ($m['role'] ?? '') === 'assistant' ? 'model' : 'user';
			if (($m['role'] ?? '') === 'system') {
				continue;
			}
			$contents[] = [
				'role' => $role,
				'parts' => [['text' => (string)($m['content'] ?? '')]],
			];
		}
		if (count($contents) === 0) {
			$contents[] = ['role' => 'user', 'parts' => [['text' => 'Olá']]];
		}

		$payload = ['contents' => $contents];
		if ($systemPrompt !== '') {
			$payload['systemInstruction'] = [
				'parts' => [['text' => $systemPrompt]],
			];
		}

		$body = json_encode($payload, JSON_UNESCAPED_UNICODE);
		$t0 = microtime(true);
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_TIMEOUT => 45,
		]);
		$raw = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		$latencyMs = (int)round((microtime(true) - $t0) * 1000);

		if ($raw === false) {
			self::$lastError = 'cURL Gemini: '.$err;
			AiUsageLogger::logFromGeminiResponse($idAdmin, $feature, $model, 0, $latencyMs, null, false, self::$lastError);
			return null;
		}
		$data = json_decode($raw, true);
		if ($code >= 400) {
			self::$lastError = 'Gemini HTTP '.$code.': '.mb_substr((string)$raw, 0, 160);
			AiUsageLogger::logFromGeminiResponse($idAdmin, $feature, $model, $code, $latencyMs, is_array($data) ? $data : null, false, self::$lastError);
			return null;
		}
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
		$ok = is_string($text) && $text !== '';
		AiUsageLogger::logFromGeminiResponse($idAdmin, $feature, $model, $code, $latencyMs, is_array($data) ? $data : null, $ok, $ok ? null : 'Resposta vazia');
		return $ok ? $text : null;
	}

	/**
	 * Corrige questão aberta. Retorna ['score'=>0-100, 'feedback'=>string, 'correct'=>bool].
	 * $expectedAnswer = gabarito opcional (campo resposta_correta). $idAdmin = tenant da escola dona do conteúdo/IA.
	 */
	public static function gradeEssay(
		int $idAdmin,
		string $prompt,
		string $answer,
		string $lessonContext = '',
		string $expectedAnswer = ''
	): array {
		$answer = trim($answer);
		if ($answer === '') {
			return ['score' => 0, 'feedback' => 'Resposta em branco.', 'correct' => false];
		}

		$local = self::tryGradeEssayLocally($prompt, $answer, trim($expectedAnswer));
		if ($local !== null) {
			return $local;
		}

		$sys = 'Você é um corretor pedagógico. Avalie a resposta do aluno de 0 a 100. '
			.'Responda APENAS JSON válido, sem markdown: {"score":0-100,"feedback":"texto curto em português","correct":true|false}. '
			.'correct=true se score>=70. Seja justo: respostas objetivamente corretas (incluindo contas simples) devem receber score alto.';
		$user = "Enunciado:\n{$prompt}\n\nResposta do aluno:\n{$answer}";
		if (trim($expectedAnswer) !== '') {
			$user .= "\n\nGabarito esperado (referência):\n".$expectedAnswer;
		}
		if ($lessonContext !== '') {
			$user = "Contexto da aula (use só como referência, sem inventar fatos):\n{$lessonContext}\n\n".$user;
		}
		$raw = self::chat($idAdmin, [['role' => 'user', 'content' => $user]], $sys, 'essay');
		$json = self::parseJsonFromLlm($raw);
		if (!is_array($json)) {
			$retry = self::avaliarExpressaoNoEnunciado($prompt, $answer);
			if ($retry !== null) {
				return $retry;
			}
			$len = mb_strlen($answer);
			$score = $len < 20 ? 40 : ($len < 80 ? 65 : 75);
			return [
				'score' => $score,
				'feedback' => 'Avaliação automática (IA indisponível no momento).',
				'correct' => $score >= 70,
			];
		}
		$score = max(0, min(100, (int)($json['score'] ?? 0)));
		$result = [
			'score' => $score,
			'feedback' => (string)($json['feedback'] ?? ''),
			'correct' => !empty($json['correct']) || $score >= 70,
		];
		if ($score < 70) {
			$override = self::tryGradeEssayLocally($prompt, $answer, trim($expectedAnswer));
			if ($override !== null && !empty($override['correct'])) {
				return $override;
			}
		}
		return $result;
	}

	/** @return array{score:int,feedback:string,correct:bool}|null */
	private static function tryGradeEssayLocally(string $prompt, string $answer, string $expected): ?array {
		if ($expected !== '') {
			if (self::respostasEquivalentes($expected, $answer)) {
				return ['score' => 100, 'feedback' => 'Resposta correta.', 'correct' => true];
			}
		}
		return self::avaliarExpressaoNoEnunciado($prompt, $answer);
	}

	/** Detecta contas simples no enunciado (ex.: "quanto é 40 - 10"). */
	private static function avaliarExpressaoNoEnunciado(string $prompt, string $answer): ?array {
		$texto = self::normalizarTextoEnunciado($prompt);
		if (!preg_match('/(\d+(?:[.,]\d+)?)\s*([+\-*\/×÷x−–—])\s*(\d+(?:[.,]\d+)?)/u', $texto, $m)) {
			return null;
		}
		$a = (float)str_replace(',', '.', $m[1]);
		$op = $m[2];
		$b = (float)str_replace(',', '.', $m[3]);
		$resultado = null;
		if ($op === '+' || $op === 'x') {
			$resultado = $a + $b;
		} elseif ($op === '-' || $op === '−' || $op === '–' || $op === '—') {
			$resultado = $a - $b;
		} elseif ($op === '*' || $op === '×') {
			$resultado = $a * $b;
		} elseif (($op === '/' || $op === '÷') && abs($b) > 0.00001) {
			$resultado = $a / $b;
		}
		if ($resultado === null) {
			return null;
		}
		$ok = self::respostasEquivalentes((string)$resultado, $answer);
		return [
			'score' => $ok ? 100 : 0,
			'feedback' => $ok ? 'Resposta correta.' : 'Resposta incorreta.',
			'correct' => $ok,
		];
	}

	private static function respostasEquivalentes(string $expected, string $given): bool {
		$e = self::normalizarRespostaTexto($expected);
		$g = self::normalizarRespostaTexto($given);
		if ($e === $g) {
			return true;
		}
		if (self::numerosEquivalentes($expected, $given)) {
			return true;
		}
		return false;
	}

	private static function normalizarRespostaTexto(string $s): string {
		$s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$s = str_replace(["\xc2\xa0", '−', '–', '—'], [' ', '-', '-', '-'], $s);
		$s = mb_strtolower(trim($s), 'UTF-8');
		$s = preg_replace('/\s+/u', ' ', $s) ?? $s;
		return $s;
	}

	private static function normalizarTextoEnunciado(string $s): string {
		$s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$s = str_replace(["\xc2\xa0", '−', '–', '—'], [' ', '-', '-', '-'], $s);
		return mb_strtolower($s, 'UTF-8');
	}

	private static function numerosEquivalentes(string $expected, string $given): bool {
		$ne = self::extrairNumero($expected);
		$ng = self::extrairNumero($given);
		if ($ne === null || $ng === null) {
			return false;
		}
		return abs($ne - $ng) < 0.01;
	}

	private static function extrairNumero(string $s): ?float {
		$s = trim(str_replace(',', '.', $s));
		if ($s === '' || !preg_match('/^-?\d+(?:\.\d+)?$/', $s)) {
			if (preg_match('/-?\d+(?:[.,]\d+)?/', $s, $m)) {
				$s = str_replace(',', '.', $m[0]);
			} else {
				return null;
			}
		}
		return is_numeric($s) ? (float)$s : null;
	}

	private static function parseJsonFromLlm(string $raw): ?array {
		$raw = trim($raw);
		if ($raw === '') {
			return null;
		}
		if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $raw, $m)) {
			$decoded = json_decode($m[1], true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}
		$start = strpos($raw, '{');
		$end = strrpos($raw, '}');
		if ($start !== false && $end !== false && $end > $start) {
			$decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
			if (is_array($decoded)) {
				return $decoded;
			}
		}
		return null;
	}

	public static function evaluateRoleplay(int $idAdmin, array $scenario, array $messages): array {
		$transcript = '';
		foreach ($messages as $m) {
			$transcript .= strtoupper((string)($m['role'] ?? '')).': '.($m['content'] ?? '')."\n";
		}
		$prompt = "Avalie esta simulação de role play. Responda APENAS JSON válido com keys: overallScore (0-100), summary, strengths (array), improvements (array), mistakes (array), reviewTopics (array).\n"
			."Cenário: ".($scenario['title'] ?? '')."\nObjetivos: ".json_encode($scenario['objectives'] ?? [], JSON_UNESCAPED_UNICODE)."\n"
			."Diálogo:\n".$transcript;
		$raw = self::chat($idAdmin, [['role' => 'user', 'content' => $prompt]], 'Você é um avaliador pedagógico rigoroso.', 'roleplay_eval');
		$json = null;
		if (preg_match('/\{.*\}/s', $raw, $m)) {
			$json = json_decode($m[0], true);
		}
		if (!is_array($json)) {
			$json = [
				'overallScore' => 75,
				'summary' => 'Avaliação automática (IA indisponível). Configure/verifique o modelo em Configurações de IA.',
				'strengths' => ['Participação na conversa'],
				'improvements' => ['Aprofundar argumentos'],
				'mistakes' => [],
				'reviewTopics' => [],
			];
		}
		$min = (int)($scenario['minScore'] ?? 70);
		$score = (int)($json['overallScore'] ?? 75);
		return [
			'overallScore' => $score,
			'passed' => $score >= $min,
			'summary' => (string)($json['summary'] ?? ''),
			'strengths' => $json['strengths'] ?? [],
			'improvements' => $json['improvements'] ?? [],
			'mistakes' => $json['mistakes'] ?? [],
			'reviewTopics' => $json['reviewTopics'] ?? [],
			'competencies' => [['key' => 'geral', 'label' => 'Desempenho geral', 'score' => $score]],
			'timeline' => [],
			'referenceConversation' => [],
		];
	}
}
