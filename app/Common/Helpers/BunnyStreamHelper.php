<?php

namespace App\Common\Helpers;

use App\Model\Entity\PlataformaBunny;

/**
 * Bunny Stream — credenciais globais (Master / plataforma_bunny).
 * O parâmetro $idAdmin é mantido por compatibilidade nas assinaturas e ignorado.
 */
class BunnyStreamHelper {

	private const API = 'https://video.bunnycdn.com';

	public static function cfg(): PlataformaBunny {
		return PlataformaBunny::get();
	}

	/** @deprecated Use cfg(); mantido para callers antigos */
	public static function config(int $idAdmin = 0): ?PlataformaBunny {
		if (!PlataformaBunny::tabelaExiste()) {
			return null;
		}
		return self::cfg();
	}

	public static function pronto(int $idAdmin = 0): bool {
		return self::cfg()->streamPronto();
	}

	/**
	 * @return array{ok:bool,videoId?:string,message?:string}
	 */
	public static function criarVideo(int $idAdmin, string $titulo): array {
		$cfg = self::cfg();
		if (!$cfg->streamPronto()) {
			return ['ok' => false, 'message' => 'Bunny Stream não configurado no Master.'];
		}
		$libraryId = trim((string)$cfg->stream_library_id);
		$key = $cfg->getStreamApiKey();
		if ($libraryId === '' || !$key) {
			return ['ok' => false, 'message' => 'Library ID ou AccessKey ausentes.'];
		}
		$titulo = trim($titulo) !== '' ? trim($titulo) : 'Aula EAD';
		$res = self::request(
			'POST',
			self::API.'/library/'.rawurlencode($libraryId).'/videos',
			$key,
			['title' => $titulo]
		);
		if (!$res['ok']) {
			return ['ok' => false, 'message' => $res['message'] ?? 'Falha ao criar vídeo no Bunny.'];
		}
		$guid = (string)($res['data']['guid'] ?? '');
		if ($guid === '') {
			return ['ok' => false, 'message' => 'Bunny não retornou o GUID do vídeo.'];
		}
		return ['ok' => true, 'videoId' => $guid];
	}

	/**
	 * @return array{ok:bool,libraryId?:string,videoId?:string,expires?:int,signature?:string,message?:string,putUrl?:string,accessKey?:string,uploadUrl?:string}
	 */
	public static function assinaturaUpload(int $idAdmin, string $videoId): array {
		$cfg = self::cfg();
		if (!$cfg->streamPronto()) {
			return ['ok' => false, 'message' => 'Bunny Stream não configurado no Master.'];
		}
		$libraryId = trim((string)$cfg->stream_library_id);
		$key = $cfg->getStreamApiKey();
		$videoId = trim($videoId);
		if ($libraryId === '' || !$key || $videoId === '') {
			return ['ok' => false, 'message' => 'Dados Bunny incompletos.'];
		}
		$expires = time() + 3600;
		$signature = hash('sha256', $libraryId.$key.$expires.$videoId);
		return [
			'ok' => true,
			'libraryId' => $libraryId,
			'videoId' => $videoId,
			'expires' => $expires,
			'signature' => $signature,
			'uploadUrl' => self::API.'/tusupload',
			'putUrl' => self::API.'/library/'.rawurlencode($libraryId).'/videos/'.rawurlencode($videoId),
			'accessKey' => $key,
		];
	}

	/**
	 * Upload binário server-side (L-Editor / PHP).
	 * @return array{ok:bool,message?:string}
	 */
	public static function uploadArquivo(int $idAdmin, string $videoId, string $tmpPath, string $contentType = 'application/octet-stream'): array {
		$auth = self::assinaturaUpload($idAdmin, $videoId);
		if (empty($auth['ok']) || empty($auth['putUrl']) || empty($auth['accessKey'])) {
			return ['ok' => false, 'message' => $auth['message'] ?? 'Assinatura inválida.'];
		}
		$raw = @file_get_contents($tmpPath);
		if ($raw === false) {
			return ['ok' => false, 'message' => 'Não foi possível ler o arquivo temporário.'];
		}
		$ch = curl_init((string)$auth['putUrl']);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => 'PUT',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 600,
			CURLOPT_HTTPHEADER => [
				'AccessKey: '.$auth['accessKey'],
				'Content-Type: '.$contentType,
			],
			CURLOPT_POSTFIELDS => $raw,
		]);
		$resp = curl_exec($ch);
		$errno = curl_errno($ch);
		$err = curl_error($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($errno) {
			return ['ok' => false, 'message' => 'cURL: '.$err];
		}
		if ($http < 200 || $http >= 300) {
			return ['ok' => false, 'message' => 'Upload Stream HTTP '.$http.($resp ? ': '.substr((string)$resp, 0, 200) : '')];
		}
		return ['ok' => true];
	}

	/**
	 * @return array{ok:bool,status?:string,length?:int,encodeProgress?:int,message?:string,bunnyCode?:int,durationMinutes?:int,title?:string}
	 */
	public static function statusVideo(int $idAdmin, string $videoId): array {
		$cfg = self::cfg();
		if (!$cfg->streamPronto()) {
			return ['ok' => false, 'message' => 'Bunny Stream não configurado no Master.'];
		}
		$libraryId = trim((string)$cfg->stream_library_id);
		$key = $cfg->getStreamApiKey();
		$videoId = trim($videoId);
		if ($libraryId === '' || !$key || $videoId === '') {
			return ['ok' => false, 'message' => 'Dados Bunny incompletos.'];
		}
		$res = self::request(
			'GET',
			self::API.'/library/'.rawurlencode($libraryId).'/videos/'.rawurlencode($videoId),
			$key
		);
		if (!$res['ok']) {
			return ['ok' => false, 'message' => $res['message'] ?? 'Falha ao consultar status.'];
		}
		$data = $res['data'] ?? [];
		$code = (int)($data['status'] ?? 0);
		$map = [
			0 => 'uploading',
			1 => 'processing',
			2 => 'processing',
			3 => 'processing',
			4 => 'ready',
			5 => 'error',
			6 => 'error',
		];
		$length = (int)($data['length'] ?? 0);
		return [
			'ok' => true,
			'status' => $map[$code] ?? 'processing',
			'bunnyCode' => $code,
			'length' => $length,
			'durationMinutes' => (int)max(1, (int)ceil($length / 60)),
			'encodeProgress' => (int)($data['encodeProgress'] ?? 0),
			'title' => (string)($data['title'] ?? ''),
		];
	}

	/**
	 * @return array{ok:bool,message?:string}
	 */
	public static function excluirVideo(int $idAdmin, string $videoId): array {
		$cfg = self::cfg();
		if (!$cfg->streamPronto()) {
			return ['ok' => true];
		}
		$libraryId = trim((string)$cfg->stream_library_id);
		$key = $cfg->getStreamApiKey();
		$videoId = trim($videoId);
		if ($libraryId === '' || !$key || $videoId === '') {
			return ['ok' => true];
		}
		$res = self::request(
			'DELETE',
			self::API.'/library/'.rawurlencode($libraryId).'/videos/'.rawurlencode($videoId),
			$key
		);
		if (!$res['ok'] && ($res['http'] ?? 0) !== 404) {
			return ['ok' => false, 'message' => $res['message'] ?? 'Falha ao excluir no Bunny.'];
		}
		return ['ok' => true];
	}

	/**
	 * @return array{ok:bool,playbackUrl?:string,expiresAt?:int,libraryId?:string,videoId?:string,message?:string}
	 */
	public static function urlPlayback(int $idAdmin, string $videoId, int $ttlSec = 7200): array {
		$cfg = self::cfg();
		if (!PlataformaBunny::tabelaExiste()) {
			return ['ok' => false, 'message' => 'Execute database/plataforma_bunny.sql no phpMyAdmin.'];
		}
		if ((int)($cfg->stream_ativo ?? 0) !== 1) {
			return ['ok' => false, 'message' => 'Bunny Stream desativado no Master.'];
		}

		$host = trim((string)($cfg->stream_cdn_hostname ?? ''));
		$host = preg_replace('#^https?://#i', '', $host);
		$host = rtrim((string)$host, '/');
		$libraryId = trim((string)($cfg->stream_library_id ?? ''));
		$videoId = trim($videoId);
		$tokenKey = $cfg->getStreamTokenKey();

		if ($host === '') {
			return ['ok' => false, 'message' => 'CDN Hostname Bunny ausente (Master → Bunny).'];
		}
		if ($videoId === '') {
			return ['ok' => false, 'message' => 'Video ID Bunny ausente.'];
		}
		if (trim((string)($cfg->stream_token_key ?? '')) !== '' && !$tokenKey) {
			return [
				'ok' => false,
				'message' => 'Token Key Bunny não pôde ser lido — cole de novo no Master → Bunny e salve.',
			];
		}

		$expires = time() + max(300, min(86400, $ttlSec));
		$plainUrl = 'https://'.$host.'/'.$videoId.'/playlist.m3u8';

		if (!$tokenKey) {
			return [
				'ok' => true,
				'playbackUrl' => $plainUrl,
				'expiresAt' => $expires,
				'libraryId' => $libraryId,
				'videoId' => $videoId,
			];
		}

		$token = hash('sha256', $tokenKey.$videoId.(string)$expires);
		$playbackUrl = $plainUrl.'?token='.$token.'&expires='.$expires;

		return [
			'ok' => true,
			'playbackUrl' => $playbackUrl,
			'expiresAt' => $expires,
			'libraryId' => $libraryId,
			'videoId' => $videoId,
		];
	}

	public static function sincronizarStatusVideo(\App\Model\Entity\LmsVideo $v, int $idAdmin): string {
		if (($v->provider ?? '') !== 'bunny' || empty($v->bunny_video_id)) {
			return (string)($v->bunny_status ?? '');
		}
		$st = self::statusVideo($idAdmin, (string)$v->bunny_video_id);
		if (empty($st['ok'])) {
			return (string)($v->bunny_status ?? 'error');
		}
		$v->bunny_status = (string)$st['status'];
		if (($st['status'] ?? '') === 'ready' && !empty($st['durationMinutes'])) {
			$v->duracao_min = (int)$st['durationMinutes'];
		}
		if (($st['status'] ?? '') === 'error') {
			$v->bunny_error = 'Falha no encode Bunny.';
		}
		$v->salvar();
		return (string)$v->bunny_status;
	}

	/**
	 * @return array{ok:bool,message?:string,name?:string}
	 */
	public static function testar(int $idAdmin = 0): array {
		if (!PlataformaBunny::tabelaExiste()) {
			return ['ok' => false, 'message' => 'Execute database/plataforma_bunny.sql'];
		}
		$cfg = self::cfg();
		$libraryId = trim((string)$cfg->stream_library_id);
		$key = $cfg->getStreamApiKey();
		if ($libraryId === '' || !$key) {
			return ['ok' => false, 'message' => 'Informe Library ID e AccessKey no Master.'];
		}
		$res = self::request(
			'GET',
			self::API.'/library/'.rawurlencode($libraryId),
			$key
		);
		if (!$res['ok']) {
			return ['ok' => false, 'message' => $res['message'] ?? 'Falha na conexão.'];
		}
		return [
			'ok' => true,
			'message' => 'Conexão Stream OK.',
			'name' => (string)($res['data']['Name'] ?? $res['data']['name'] ?? 'Library'),
		];
	}

	/**
	 * @return array{ok:bool,http?:int,data?:array,message?:string}
	 */
	private static function request(string $method, string $url, string $accessKey, ?array $jsonBody = null): array {
		$ch = curl_init($url);
		$headers = [
			'Accept: application/json',
			'AccessKey: '.$accessKey,
		];
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_HTTPHEADER => $headers,
		];
		if ($jsonBody !== null) {
			$body = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
			$headers[] = 'Content-Type: application/json';
			$opts[CURLOPT_HTTPHEADER] = $headers;
			$opts[CURLOPT_POSTFIELDS] = $body;
		}
		curl_setopt_array($ch, $opts);
		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$err = curl_error($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($errno) {
			return ['ok' => false, 'http' => 0, 'message' => 'cURL: '.$err];
		}
		$data = null;
		if (is_string($raw) && $raw !== '') {
			$decoded = json_decode($raw, true);
			$data = is_array($decoded) ? $decoded : null;
		}
		if ($http < 200 || $http >= 300) {
			$msg = is_array($data)
				? (string)($data['Message'] ?? $data['message'] ?? $data['title'] ?? ('HTTP '.$http))
				: ('HTTP '.$http);
			return ['ok' => false, 'http' => $http, 'data' => $data ?: [], 'message' => $msg];
		}
		return ['ok' => true, 'http' => $http, 'data' => $data ?: []];
	}
}
