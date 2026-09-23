<?php

namespace App\Common\Communication;

use App\Common\Environment;

/**
 * Cliente HTTP para Evolution API (WhatsApp).
 * Credenciais globais no .env; cada escola usa uma instância própria.
 */
class EvolutionApiService {

	private $baseUrl;
	private $apiKey;
	private $lastError = null;
	private $lastHttpCode = 0;

	public function __construct(?string $baseUrl = null, ?string $apiKey = null) {
		$this->baseUrl = rtrim($baseUrl ?? (string)Environment::get('EVOLUTION_URL', ''), '/');
		$this->apiKey = $apiKey ?? (string)Environment::get('EVOLUTION_API_KEY', '');
	}

	public static function fromEnv(): self {
		return new self();
	}

	public function isConfigured(): bool {
		return $this->baseUrl !== '' && $this->apiKey !== '';
	}

	public function getBaseUrl(): string {
		return $this->baseUrl;
	}

	public function getLastError(): ?string {
		return $this->lastError;
	}

	public function getLastHttpCode(): int {
		return $this->lastHttpCode;
	}

	public static function nomeInstancia(int $idAdmin): string {
		return 'escola_'.(int)$idAdmin;
	}

	public static function webhookToken(int $idAdmin): string {
		$secret = (string)(Environment::get('EVOLUTION_WEBHOOK_SECRET')
			?: Environment::get('SYSTEM_TOKEN')
			?: 'painel-cti');
		return hash_hmac('sha256', 'evolution-webhook-'.(int)$idAdmin, $secret);
	}

	public static function webhookUrl(int $idAdmin): string {
		$token = self::webhookToken($idAdmin);
		return rtrim((string)URL, '/').'/webhook/evolution/'.(int)$idAdmin.'/'.$token;
	}

	/** Normaliza telefone BR para dígitos (com DDI 55 quando possível). */
	public static function normalizarTelefone(string $telefone): string {
		$digitos = preg_replace('/\D+/', '', $telefone) ?? '';
		if ($digitos === '') {
			return '';
		}
		if (strpos($digitos, '55') === 0 && strlen($digitos) >= 12) {
			return $digitos;
		}
		if (strlen($digitos) === 10 || strlen($digitos) === 11) {
			return '55'.$digitos;
		}
		return $digitos;
	}

	/**
	 * Destino para sendText: telefone, grupo (@g.us) ou lista (@broadcast).
	 */
	public static function normalizarDestino(string $destino): string {
		$destino = trim($destino);
		if ($destino === '') {
			return '';
		}
		$lower = strtolower($destino);
		if (strpos($lower, '@g.us') !== false || strpos($lower, '@broadcast') !== false) {
			return $destino;
		}
		if (strpos($lower, '@s.whatsapp.net') !== false) {
			return self::normalizarTelefone(explode('@', $destino)[0]);
		}
		return self::normalizarTelefone($destino);
	}

	public static function isJidGrupoOuLista(string $destino): bool {
		$lower = strtolower(trim($destino));
		return strpos($lower, '@g.us') !== false || strpos($lower, '@broadcast') !== false;
	}

	/** Lista grupos da instância. */
	public function fetchAllGroups(string $instance, bool $getParticipants = false): ?array {
		$q = $getParticipants ? 'true' : 'false';
		return $this->request(
			'GET',
			'/group/fetchAllGroups/'.rawurlencode($instance).'?getParticipants='.$q
		);
	}

	/** Lista chats (pode incluir listas de transmissão). */
	public function findChats(string $instance): ?array {
		$res = $this->request('POST', '/chat/findChats/'.rawurlencode($instance), []);
		if ($res !== null && $this->lastHttpCode < 400) {
			return $res;
		}
		return $this->request('GET', '/chat/findChats/'.rawurlencode($instance));
	}

	public function fetchInstances(): ?array {
		return $this->request('GET', '/instance/fetchInstances');
	}

	/** Confirma se a instância existe (connectionState às vezes dá 404 falso durante o QR). */
	public function instanciaExiste(string $instance): bool {
		$state = $this->connectionState($instance);
		if ($state !== null && $this->lastHttpCode < 400) {
			return true;
		}
		// HTTP 500/403 etc. não significa que a instância existe — confirma na listagem.

		$list = $this->fetchInstances();
		if (!is_array($list)) {
			return false;
		}

		$itens = isset($list[0]) || $list === [] ? $list : ($list['instance'] ?? $list);
		if (!is_array($itens)) {
			return false;
		}

		$alvo = strtolower($instance);
		foreach ($itens as $item) {
			if (!is_array($item)) {
				continue;
			}
			$sub = is_array($item['instance'] ?? null) ? $item['instance'] : [];
			$nome = (string)(
				$item['name']
				?? $item['instanceName']
				?? $sub['instanceName']
				?? $sub['name']
				?? ''
			);
			if ($nome !== '' && strtolower($nome) === $alvo) {
				return true;
			}
		}

		return false;
	}

	public function connectionState(string $instance): ?array {
		return $this->request('GET', '/instance/connectionState/'.rawurlencode($instance));
	}

	public function connect(string $instance): ?array {
		return $this->request('GET', '/instance/connect/'.rawurlencode($instance));
	}

	public function createInstance(string $instanceName, ?string $webhookUrl = null): ?array {
		$body = [
			'instanceName' => $instanceName,
			'qrcode'       => true,
			'integration'  => 'WHATSAPP-BAILEYS',
		];

		if ($webhookUrl) {
			$body['webhook'] = self::payloadWebhook($webhookUrl);
		}

		return $this->request('POST', '/instance/create', $body);
	}

	public function setWebhook(string $instance, string $webhookUrl): ?array {
		$payloads = [
			['webhook' => self::payloadWebhook($webhookUrl)],
			[
				'enabled'  => true,
				'url'      => $webhookUrl,
				'webhookByEvents' => false,
				'webhookBase64'   => true,
				'events'   => self::eventosWebhook(),
			],
			self::payloadWebhook($webhookUrl),
		];

		$ultimo = null;
		foreach ($payloads as $body) {
			$ultimo = $this->request('POST', '/webhook/set/'.rawurlencode($instance), $body);
			if ($ultimo !== null && $this->lastHttpCode < 400) {
				return $ultimo;
			}
		}

		return $ultimo;
	}

	public function getWebhook(string $instance): ?array {
		$res = $this->request('GET', '/webhook/find/'.rawurlencode($instance));
		if ($res !== null && $this->lastHttpCode < 400) {
			return $res;
		}
		return $this->request('GET', '/webhook/'.rawurlencode($instance));
	}

	/** Verifica se a URL do webhook na Evolution bate com a esperada. */
	public function webhookEstaConfigurado(string $instance, string $urlEsperada): bool {
		$wh = $this->getWebhook($instance);
		if ($wh === null || $this->getLastHttpCode() >= 400) {
			return false;
		}
		$urlCfg = (string)(
			$wh['url']
			?? ($wh['webhook']['url'] ?? null)
			?? ($wh['webhookUrl'] ?? null)
			?? ''
		);
		return rtrim($urlCfg, '/') === rtrim($urlEsperada, '/');
	}

	/**
	 * Resolve o nome real da instância na Evolution (pode diferir de escola_ID).
	 */
	public function resolverNomeInstancia(string $preferido): string {
		if ($this->instanciaExiste($preferido)) {
			return $preferido;
		}

		$list = $this->fetchInstances();
		if (!is_array($list)) {
			return $preferido;
		}

		$itens = isset($list[0]) || $list === [] ? $list : ($list['instance'] ?? $list);
		if (!is_array($itens)) {
			return $preferido;
		}

		$alvo = strtolower($preferido);
		foreach ($itens as $item) {
			if (!is_array($item)) {
				continue;
			}
			$sub = is_array($item['instance'] ?? null) ? $item['instance'] : [];
			$nome = (string)(
				$item['name']
				?? $item['instanceName']
				?? $sub['instanceName']
				?? $sub['name']
				?? ''
			);
			if ($nome !== '' && strtolower($nome) === $alvo) {
				return $nome;
			}
		}

		return $preferido;
	}

	/** Dados da instância na listagem (owner, status). */
	public function obterDadosInstanciaLista(string $instance): ?array {
		$list = $this->fetchInstances();
		if (!is_array($list)) {
			return null;
		}
		$itens = isset($list[0]) || $list === [] ? $list : ($list['instance'] ?? $list);
		if (!is_array($itens)) {
			return null;
		}
		$alvo = strtolower($instance);
		foreach ($itens as $item) {
			if (!is_array($item)) {
				continue;
			}
			$sub = is_array($item['instance'] ?? null) ? $item['instance'] : [];
			$nome = (string)(
				$item['name']
				?? $item['instanceName']
				?? $sub['instanceName']
				?? $sub['name']
				?? ''
			);
			if ($nome !== '' && strtolower($nome) === $alvo) {
				return $item;
			}
		}
		return null;
	}

	public function restartInstance(string $instance): ?array {
		$res = $this->request('POST', '/instance/restart/'.rawurlencode($instance));
		if ($res !== null && $this->lastHttpCode < 400) {
			return $res;
		}
		return $this->request('PUT', '/instance/restart/'.rawurlencode($instance));
	}

	/**
	 * Logout + delete com confirmação (404 sozinho NÃO significa sucesso).
	 * @return array{ok:bool,passos:list<string>,ultimo_erro:?string}
	 */
	public function removerInstanciaDetalhado(string $instance): array {
		$passos = [];

		if (!$this->instanciaExiste($instance)) {
			$passos[] = 'existe:não';
			return ['ok' => true, 'passos' => $passos, 'ultimo_erro' => null];
		}

		$passos[] = 'existe:sim';

		$this->deleteInstanceForce($instance);
		$passos[] = 'delete1:HTTP '.$this->lastHttpCode;
		if (!$this->instanciaExiste($instance)) {
			return ['ok' => true, 'passos' => $passos, 'ultimo_erro' => null];
		}

		for ($i = 0; $i < 3; $i++) {
			$this->logout($instance);
			$passos[] = 'logout'.($i + 1).':HTTP '.$this->lastHttpCode;
			usleep(800000);
			$this->deleteInstanceForce($instance);
			$passos[] = 'delete'.($i + 2).':HTTP '.$this->lastHttpCode;
			if (!$this->instanciaExiste($instance)) {
				return ['ok' => true, 'passos' => $passos, 'ultimo_erro' => null];
			}
		}

		$this->restartInstance($instance);
		$passos[] = 'restart:HTTP '.$this->lastHttpCode;
		usleep(800000);
		$this->logout($instance);
		$passos[] = 'logout-final:HTTP '.$this->lastHttpCode;
		usleep(500000);
		$this->deleteInstanceForce($instance);
		$passos[] = 'delete-final:HTTP '.$this->lastHttpCode;

		$ok = !$this->instanciaExiste($instance);
		$passos[] = 'resultado:'.($ok ? 'ok' : 'fail');
		return [
			'ok' => $ok,
			'passos' => $passos,
			'ultimo_erro' => $ok ? null : ($this->getLastError() ?: 'HTTP '.$this->getLastHttpCode()),
		];
	}

	public function removerInstancia(string $instance): bool {
		return $this->removerInstanciaDetalhado($instance)['ok'];
	}

	private static function eventosWebhook(): array {
		return [
			'MESSAGES_UPSERT',
			'MESSAGES_UPDATE',
			'CONNECTION_UPDATE',
			'QRCODE_UPDATED',
			'SEND_MESSAGE',
		];
	}

	private static function payloadWebhook(string $webhookUrl): array {
		return [
			'enabled'  => true,
			'url'      => $webhookUrl,
			'byEvents' => false,
			'base64'   => true,
			'events'   => self::eventosWebhook(),
		];
	}

	public function sendText(string $instance, string $number, string $text): ?array {
		$number = self::normalizarDestino($number);
		if ($number === '' || trim($text) === '') {
			$this->lastError = 'Número ou mensagem inválidos.';
			return null;
		}

		return $this->request('POST', '/message/sendText/'.rawurlencode($instance), [
			'number' => $number,
			'text'   => $text,
		]);
	}

	/**
	 * Envia imagem/documento/vídeo.
	 * Prefere arquivo local (multipart); fallback JSON base64.
	 */
	public function sendMedia(
		string $instance,
		string $number,
		string $media,
		string $mediatype = 'image',
		?string $mimetype = null,
		?string $caption = null,
		?string $fileName = null
	): ?array {
		$number = self::normalizarDestino($number);
		if ($number === '' || $media === '') {
			$this->lastError = 'Número ou mídia inválidos.';
			return null;
		}

		// Caminho de arquivo local → multipart (mais compatível)
		if (is_file($media)) {
			$res = $this->requestMultipart(
				'/message/sendMedia/'.rawurlencode($instance),
				[
					'number'    => $number,
					'mediatype' => $mediatype,
					'caption'   => $caption,
					'fileName'  => $fileName ?: basename($media),
					'mimetype'  => $mimetype,
				],
				'media',
				$media,
				$mimetype,
				$fileName ?: basename($media)
			);
			if ($res !== null && $this->lastHttpCode < 400) {
				return $res;
			}
			// tenta campo "file" (algumas versões)
			$res2 = $this->requestMultipart(
				'/message/sendMedia/'.rawurlencode($instance),
				[
					'number'    => $number,
					'mediatype' => $mediatype,
					'caption'   => $caption,
					'fileName'  => $fileName ?: basename($media),
					'mimetype'  => $mimetype,
				],
				'file',
				$media,
				$mimetype,
				$fileName ?: basename($media)
			);
			if ($res2 !== null && $this->lastHttpCode < 400) {
				return $res2;
			}
		}

		// JSON: data URI ou base64 puro
		$payloadMedia = $media;
		if (is_file($media)) {
			$bin = file_get_contents($media);
			if ($bin === false) {
				$this->lastError = 'Não foi possível ler o arquivo de mídia.';
				return null;
			}
			$mime = $mimetype ?: 'application/octet-stream';
			$payloadMedia = 'data:'.$mime.';base64,'.base64_encode($bin);
		}

		$body = [
			'number'    => $number,
			'mediatype' => $mediatype,
			'media'     => $payloadMedia,
		];
		if ($mimetype) {
			$body['mimetype'] = $mimetype;
		}
		if ($caption !== null && $caption !== '') {
			$body['caption'] = $caption;
		}
		if ($fileName) {
			$body['fileName'] = $fileName;
		} elseif ($mediatype === 'document') {
			$body['fileName'] = 'documento.pdf';
		}

		$res = $this->request('POST', '/message/sendMedia/'.rawurlencode($instance), $body);
		if ($res !== null && $this->lastHttpCode < 400) {
			return $res;
		}

		// Última tentativa: base64 sem prefixo data:
		if (strpos($payloadMedia, 'base64,') !== false) {
			$parts = explode('base64,', $payloadMedia, 2);
			$body['media'] = $parts[1] ?? $payloadMedia;
			$res = $this->request('POST', '/message/sendMedia/'.rawurlencode($instance), $body);
		}

		return $res;
	}

	/**
	 * Status "gravando áudio..." no WhatsApp do destinatário (best-effort).
	 */
	public function sendPresence(string $instance, string $number, string $presence = 'recording'): ?array {
		$number = self::normalizarDestino($number);
		if ($number === '') {
			return null;
		}

		return $this->request('POST', '/chat/sendPresence/'.rawurlencode($instance), [
			'number'   => $number,
			'presence' => $presence,
		]);
	}

	/**
	 * Envia nota de voz (PTT) — aparece como áudio gravado no WhatsApp.
	 * Nunca usa documento. encoding=true pede conversão Opus na Evolution.
	 */
	public function sendAudio(string $instance, string $number, string $audio, ?string $mimetype = null): ?array {
		$number = self::normalizarDestino($number);
		if ($number === '' || $audio === '') {
			$this->lastError = 'Número ou áudio inválidos.';
			return null;
		}

		$endpoint = '/message/sendWhatsAppAudio/'.rawurlencode($instance);

		// URL pública ou data URI / base64
		if (!is_file($audio)) {
			return $this->request('POST', $endpoint, [
				'number'   => $number,
				'audio'    => $audio,
				'encoding' => true,
			]);
		}

		$mime = $mimetype;
		$fileName = basename($audio);
		if (!$mime) {
			$detected = @mime_content_type($audio);
			$mime = is_string($detected) && $detected !== '' ? $detected : null;
		}
		if (!$mime) {
			$ext = strtolower(pathinfo($audio, PATHINFO_EXTENSION));
			$map = [
				'wav'  => 'audio/wav',
				'mp3'  => 'audio/mpeg',
				'ogg'  => 'audio/ogg; codecs=opus',
				'opus' => 'audio/ogg; codecs=opus',
				'm4a'  => 'audio/mp4',
				'aac'  => 'audio/aac',
				'webm' => 'audio/webm',
			];
			$mime = $map[$ext] ?? 'audio/ogg';
		}

		// 1) Multipart → sendWhatsAppAudio (PTT)
		$resPtt = $this->requestMultipart(
			$endpoint,
			[
				'number'   => $number,
				'encoding' => 'true',
			],
			'audio',
			$audio,
			$mime,
			$fileName
		);
		if ($resPtt !== null && $this->lastHttpCode < 400) {
			return $resPtt;
		}

		// 2) JSON base64 / data URI
		$bin = file_get_contents($audio);
		if ($bin === false) {
			$this->lastError = 'Não foi possível ler o arquivo de áudio.';
			return null;
		}

		$tentativas = [
			['number' => $number, 'audio' => 'data:'.$mime.';base64,'.base64_encode($bin), 'encoding' => true],
			['number' => $number, 'audio' => base64_encode($bin), 'encoding' => true],
		];
		$ultimo = $resPtt;
		foreach ($tentativas as $body) {
			$ultimo = $this->request('POST', $endpoint, $body);
			if ($ultimo !== null && $this->lastHttpCode < 400) {
				return $ultimo;
			}
		}

		return $ultimo;
	}

	/**
	 * Baixa mídia de uma mensagem (fallback se webhook não trouxer base64).
	 */
	public function getBase64FromMediaMessage(string $instance, array $messagePayload): ?array {
		return $this->request('POST', '/chat/getBase64FromMediaMessage/'.rawurlencode($instance), [
			'message' => $messagePayload,
			'convertToMp4' => false,
		]);
	}

	public function logout(string $instance): ?array {
		$tentativas = [
			['DELETE', '/instance/logout/'.rawurlencode($instance), null],
			['POST', '/instance/logout/'.rawurlencode($instance), []],
			['DELETE', '/instance/logout/'.$instance, null],
			['POST', '/instance/logout', ['instanceName' => $instance]],
		];
		$ultimo = null;
		foreach ($tentativas as [$method, $path, $body]) {
			$ultimo = $body !== null
				? $this->request($method, $path, $body)
				: $this->request($method, $path);
			if ($this->lastHttpCode >= 200 && $this->lastHttpCode < 300) {
				return $ultimo;
			}
			if ($this->lastHttpCode === 404) {
				return $ultimo;
			}
		}
		return $ultimo;
	}

	public function deleteInstance(string $instance): ?array {
		return $this->deleteInstanceForce($instance);
	}

	private function deleteInstanceForce(string $instance): ?array {
		$tentativas = [
			['DELETE', '/instance/delete/'.rawurlencode($instance), null],
			['DELETE', '/instance/delete/'.$instance, null],
			['DELETE', '/instance/'.rawurlencode($instance), null],
			['POST', '/instance/delete', ['instanceName' => $instance]],
			['DELETE', '/instance/delete', ['instanceName' => $instance]],
			['POST', '/instance/delete/'.rawurlencode($instance), []],
		];

		$ultimo = null;
		foreach ($tentativas as [$method, $path, $body]) {
			$ultimo = $body !== null
				? $this->request($method, $path, $body)
				: $this->request($method, $path);
			if ($this->lastHttpCode >= 200 && $this->lastHttpCode < 300) {
				return $ultimo;
			}
		}

		return $ultimo;
	}

	/**
	 * Extrai status de conexão de respostas variadas da Evolution.
	 */
	public static function extrairEstado(?array $payload): string {
		if ($payload === null) {
			return 'unknown';
		}

		$candidatos = [
			$payload['instance']['state'] ?? null,
			$payload['instance']['status'] ?? null,
			$payload['state'] ?? null,
			$payload['status'] ?? null,
			$payload['connectionStatus'] ?? null,
		];

		foreach ($candidatos as $v) {
			if (is_string($v) && $v !== '') {
				return strtolower($v);
			}
		}

		return 'unknown';
	}

	/**
	 * Extrai imagem do QR (data URI). Não usa o campo "code" (texto do WhatsApp).
	 */
	public static function extrairQrBase64(?array $payload): ?string {
		if ($payload === null) {
			return null;
		}

		$candidatos = [];

		foreach ([
			$payload['base64'] ?? null,
			is_string($payload['qrcode'] ?? null) ? $payload['qrcode'] : null,
			$payload['qrcode']['base64'] ?? null,
			$payload['qr']['base64'] ?? null,
			$payload['data']['base64'] ?? null,
			$payload['data']['qrcode']['base64'] ?? null,
		] as $v) {
			if (is_string($v) && $v !== '') {
				$candidatos[] = $v;
			}
		}

		foreach ($candidatos as $v) {
			$img = self::normalizarImagemQr($v);
			if ($img !== null) {
				return $img;
			}
		}

		return null;
	}

	/**
	 * Texto bruto do QR (campo code da Evolution) — precisa virar imagem.
	 */
	public static function extrairQrCodeString(?array $payload): ?string {
		if ($payload === null) {
			return null;
		}

		$candidatos = [
			$payload['code'] ?? null,
			$payload['qrcode']['code'] ?? null,
			$payload['qr']['code'] ?? null,
			$payload['data']['code'] ?? null,
			$payload['data']['qrcode']['code'] ?? null,
		];

		foreach ($candidatos as $v) {
			if (!is_string($v) || $v === '') {
				continue;
			}
			// Evita confundir base64/data URI com o code do WhatsApp
			if (self::pareceImagemBase64($v)) {
				continue;
			}
			return $v;
		}

		return null;
	}

	/**
	 * Data URI da imagem, ou URL de QR gerado a partir do code.
	 */
	public static function montarQrParaExibicao(?array $payload): ?string {
		$img = self::extrairQrBase64($payload);
		if ($img !== null) {
			return $img;
		}

		$code = self::extrairQrCodeString($payload);
		if ($code === null || $code === '') {
			return null;
		}

		return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&ecc=M&margin=10&data='
			.rawurlencode($code);
	}

	private static function normalizarImagemQr(string $v): ?string {
		$v = trim($v);
		if ($v === '') {
			return null;
		}
		if (strpos($v, 'data:image') === 0) {
			return $v;
		}
		if (!self::pareceImagemBase64($v)) {
			return null;
		}
		return 'data:image/png;base64,'.$v;
	}

	private static function pareceImagemBase64(string $v): bool {
		if (strpos($v, 'data:image') === 0) {
			return true;
		}
		// PNG/JPEG em base64 costumam começar assim; o code do WA tem "@" e é bem diferente
		if (strpos($v, '@') !== false) {
			return false;
		}
		if (strlen($v) < 64) {
			return false;
		}
		$sample = substr($v, 0, 32);
		return (bool)preg_match('#^[A-Za-z0-9+/]#', $sample)
			&& (strpos($sample, 'iVBOR') === 0 || strpos($sample, '/9j/') === 0 || strlen($v) > 200);
	}

	/**
	 * Tenta obter QR com algumas tentativas (a Evolution às vezes demora 1–2s).
	 */
	public function obterQrComRetry(string $instance, int $tentativas = 4, int $sleepMs = 800): ?array {
		$ultimo = null;
		for ($i = 0; $i < $tentativas; $i++) {
			if ($i > 0) {
				usleep($sleepMs * 1000);
			}
			$ultimo = $this->connect($instance);
			if ($ultimo === null) {
				continue;
			}
			if (self::montarQrParaExibicao($ultimo) !== null) {
				return $ultimo;
			}
			$estado = self::extrairEstado($ultimo);
			if (in_array($estado, ['open', 'connected'], true)) {
				return $ultimo;
			}
		}
		return $ultimo;
	}

	private function request(string $method, string $path, ?array $body = null): ?array {
		$this->lastError = null;
		$this->lastHttpCode = 0;

		if (!$this->isConfigured()) {
			$this->lastError = 'Evolution API não configurada no .env (EVOLUTION_URL / EVOLUTION_API_KEY).';
			return null;
		}

		$url = $this->baseUrl.$path;
		$ch = curl_init($url);
		$headers = [
			'apikey: '.$this->apiKey,
			'Content-Type: application/json',
			'Accept: application/json',
		];

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => strtoupper($method),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_CONNECTTIMEOUT => 20,
			CURLOPT_SSL_VERIFYPEER => true,
		]);

		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
		}

		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$this->lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return $this->parseResponse($raw, $errno, $error);
	}

	/**
	 * POST multipart (envio de arquivo).
	 * @param array<string,mixed> $fields
	 */
	private function requestMultipart(
		string $path,
		array $fields,
		string $fileField,
		string $filePath,
		?string $mime,
		?string $fileName
	): ?array {
		$this->lastError = null;
		$this->lastHttpCode = 0;

		if (!$this->isConfigured()) {
			$this->lastError = 'Evolution API não configurada no .env (EVOLUTION_URL / EVOLUTION_API_KEY).';
			return null;
		}
		if (!is_file($filePath)) {
			$this->lastError = 'Arquivo de mídia não encontrado.';
			return null;
		}

		$post = [];
		foreach ($fields as $k => $v) {
			if ($v === null || $v === '') {
				continue;
			}
			$post[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string)$v;
		}

		$post[$fileField] = new \CURLFile(
			$filePath,
			$mime ?: mime_content_type($filePath) ?: 'application/octet-stream',
			$fileName ?: basename($filePath)
		);

		$url = $this->baseUrl.$path;
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $post,
			CURLOPT_HTTPHEADER     => [
				'apikey: '.$this->apiKey,
				'Accept: application/json',
			],
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_CONNECTTIMEOUT => 20,
		]);

		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$error = curl_error($ch);
		$this->lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return $this->parseResponse($raw, $errno, $error);
	}

	private function parseResponse($raw, int $errno, string $error): ?array {
		if ($errno) {
			$this->lastError = 'Falha de conexão com Evolution: '.$error;
			return null;
		}

		$decoded = json_decode((string)$raw, true);
		if (!is_array($decoded)) {
			$this->lastError = 'Resposta inválida da Evolution (HTTP '.$this->lastHttpCode.').';
			return null;
		}

		if ($this->lastHttpCode >= 400) {
			$this->lastError = self::extrairMensagemErro($decoded) ?: ('Erro Evolution HTTP '.$this->lastHttpCode);
			return $decoded;
		}

		return $decoded;
	}

	public static function extrairMensagemErro(array $decoded): ?string {
		$candidatos = [
			$decoded['response']['message'] ?? null,
			$decoded['message'] ?? null,
			$decoded['error']['message'] ?? null,
			is_string($decoded['error'] ?? null) ? $decoded['error'] : null,
		];

		$msg = null;
		foreach ($candidatos as $candidato) {
			if ($candidato === null || $candidato === '') {
				continue;
			}
			if (is_array($candidato)) {
				$flat = [];
				array_walk_recursive($candidato, function ($v) use (&$flat) {
					if (is_string($v) && $v !== '') {
						$flat[] = $v;
					}
				});
				$candidato = $flat ? implode(' | ', $flat) : json_encode($candidato, JSON_UNESCAPED_UNICODE);
			}
			if (is_string($candidato) && $candidato !== '') {
				$msg = $candidato;
				break;
			}
		}

		if ($msg === null) {
			return null;
		}

		if (strcasecmp(trim($msg), 'Internal Server Error') === 0) {
			return 'WhatsApp desconectado ou instável na Evolution (Internal Server Error).';
		}

		return $msg;
	}
}
