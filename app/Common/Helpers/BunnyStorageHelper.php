<?php

namespace App\Common\Helpers;

use App\Model\Entity\PlataformaBunny;

/**
 * Bunny Storage + CDN (imagens/áudios) — credenciais globais do Master.
 */
class BunnyStorageHelper {

	public static function cfg(): PlataformaBunny {
		return PlataformaBunny::get();
	}

	public static function pronto(): bool {
		return self::cfg()->storagePronto();
	}

	/**
	 * Upload de arquivo local para a Storage Zone.
	 * @return array{ok:bool,url?:string,path?:string,message?:string}
	 */
	public static function upload(string $tmpPath, string $remotePath, string $contentType = 'application/octet-stream'): array {
		$cfg = self::cfg();
		if (!$cfg->storagePronto()) {
			return ['ok' => false, 'message' => 'Bunny Storage não configurado no Master.'];
		}
		$zone = trim((string)$cfg->storage_zone);
		$key = $cfg->getStorageAccessKey();
		$endpoint = trim((string)($cfg->storage_endpoint ?: 'storage.bunnycdn.com'));
		$endpoint = preg_replace('#^https?://#i', '', $endpoint);
		$endpoint = rtrim((string)$endpoint, '/');

		$remotePath = ltrim(str_replace('\\', '/', $remotePath), '/');
		if ($zone === '' || !$key || $remotePath === '') {
			return ['ok' => false, 'message' => 'Dados Storage incompletos.'];
		}
		$raw = @file_get_contents($tmpPath);
		if ($raw === false) {
			return ['ok' => false, 'message' => 'Não foi possível ler o arquivo.'];
		}

		$parts = array_map('rawurlencode', explode('/', $remotePath));
		$url = 'https://'.$endpoint.'/'.rawurlencode($zone).'/'.implode('/', $parts);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => 'PUT',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_HTTPHEADER => [
				'AccessKey: '.$key,
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
			return ['ok' => false, 'message' => 'Storage HTTP '.$http.($resp ? ': '.substr((string)$resp, 0, 200) : '')];
		}

		$public = self::publicUrl($remotePath);
		if ($public === '') {
			return ['ok' => false, 'message' => 'Upload OK, mas CDN hostname ausente.'];
		}
		return ['ok' => true, 'url' => $public, 'path' => $remotePath];
	}

	public static function publicUrl(string $remotePath): string {
		$cfg = self::cfg();
		$host = trim((string)($cfg->storage_cdn_hostname ?? ''));
		$host = preg_replace('#^https?://#i', '', $host);
		$host = rtrim((string)$host, '/');
		$remotePath = ltrim(self::normalizeStoragePath($remotePath), '/');
		if ($host === '' || $remotePath === '') {
			return '';
		}
		$parts = array_map('rawurlencode', explode('/', $remotePath));
		return 'https://'.$host.'/'.implode('/', $parts);
	}

	/** Remove prefixo da Storage Zone do path (Pull Zone pode expor zone/path). */
	public static function normalizeStoragePath(string $path): string {
		$path = ltrim(str_replace('\\', '/', $path), '/');
		if ($path === '') {
			return '';
		}
		$zone = trim((string)(self::cfg()->storage_zone ?? ''));
		if ($zone !== '' && str_starts_with($path, $zone.'/')) {
			$path = substr($path, strlen($zone) + 1);
		}
		return ltrim($path, '/');
	}

	private static function isInterativaPath(string $path): bool {
		return str_starts_with(self::normalizeStoragePath($path), 'interativa/');
	}

	/** Extrai path remoto de uma URL pública do nosso CDN Storage (ou null). */
	public static function pathFromPublicUrl(string $url): ?string {
		$url = trim($url);
		if ($url === '' || !preg_match('#^https?://#i', $url)) {
			return null;
		}
		$cfg = self::cfg();
		$host = trim((string)($cfg->storage_cdn_hostname ?? ''));
		$host = preg_replace('#^https?://#i', '', $host);
		$host = strtolower(rtrim((string)$host, '/'));
		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['host'])) {
			return null;
		}
		$path = ltrim((string)($parts['path'] ?? ''), '/');
		if ($path === '') {
			return null;
		}
		$segments = array_map('rawurldecode', explode('/', $path));
		$remote = self::normalizeStoragePath(implode('/', $segments));
		if ($remote === '' || !self::isInterativaPath($remote)) {
			return null;
		}
		$urlHost = strtolower((string)$parts['host']);
		if ($host !== '' && $urlHost === $host) {
			return $remote;
		}
		// Fallback: path interativa/ em qualquer host b-cdn
		return $remote;
	}

	/** @return array{ok:bool,message?:string} */
	public static function deleteByPublicUrl(string $url): array {
		$path = self::pathFromPublicUrl($url);
		if ($path === null) {
			return ['ok' => true];
		}
		return self::delete($path);
	}

	public static function mimeFromExtension(string $ext, string $fallback = 'application/octet-stream'): string {
		$ext = strtolower($ext);
		$map = [
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png' => 'image/png',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			'mp3' => 'audio/mpeg',
			'wav' => 'audio/wav',
			'ogg' => 'audio/ogg',
			'm4a' => 'audio/mp4',
			'mp4' => 'video/mp4',
			'webm' => 'video/webm',
			'mov' => 'video/quicktime',
		];
		return $map[$ext] ?? $fallback;
	}

	/**
	 * Token assinado para <audio>/<img> sem JWT no header.
	 */
	public static function signFileToken(string $remotePath, int $ttlSec = 86400): string {
		$remotePath = self::normalizeStoragePath($remotePath);
		if (!self::isInterativaPath($remotePath)) {
			$remotePath = ltrim(str_replace('\\', '/', $remotePath), '/');
		}
		$payload = json_encode([
			'p' => $remotePath,
			'e' => time() + max(300, min(86400, $ttlSec)),
		], JSON_UNESCAPED_SLASHES);
		$sig = hash_hmac('sha256', (string)$payload, self::tokenSecret());
		$payloadB64 = rtrim(strtr(base64_encode((string)$payload), '+/', '-_'), '=');
		return $payloadB64.'.'.$sig;
	}

	public static function verifyFileToken(string $token): ?string {
		return self::pathFromFileToken($token, false);
	}

	/** Extrai path do token; opcionalmente ignora expiração (migração / redirect legado → CDN pública). */
	public static function pathFromFileToken(string $token, bool $ignoreExpiry = false): ?string {
		$token = trim($token);
		if ($token === '' || strpos($token, '.') === false) {
			return null;
		}
		[$payloadB64, $sig] = explode('.', $token, 2);
		if ($payloadB64 === '' || $sig === '') {
			return null;
		}
		$b64 = strtr($payloadB64, '-_', '+/');
		$pad = strlen($b64) % 4;
		if ($pad) {
			$b64 .= str_repeat('=', 4 - $pad);
		}
		$payload = base64_decode($b64, true);
		if ($payload === false || $payload === '') {
			return null;
		}
		$expect = hash_hmac('sha256', $payload, self::tokenSecret());
		if (!hash_equals($expect, $sig)) {
			return null;
		}
		$data = json_decode($payload, true);
		if (!is_array($data) || empty($data['p']) || empty($data['e'])) {
			return null;
		}
		if (!$ignoreExpiry && (int)$data['e'] < time()) {
			return null;
		}
		$path = self::normalizeStoragePath((string)$data['p']);
		if ($path === '' || strpos($path, '..') !== false) {
			return null;
		}
		if (!self::isInterativaPath($path)) {
			return null;
		}
		return $path;
	}

	/**
	 * Normaliza URL de mídia para gravar no banco (sempre CDN estável, nunca proxy).
	 */
	public static function canonicalPublicUrl(?string $url): ?string {
		$url = trim((string)$url);
		if ($url === '') {
			return null;
		}
		// Token do proxy → path → CDN (aceita token expirado: destino é CDN pública)
		if (preg_match('#[?&]t=([^&]+)#', $url, $m)) {
			$token = rawurldecode($m[1]);
			$path = self::pathFromFileToken($token, true);
			if ($path !== null) {
				$cdn = self::publicUrl($path);
				return $cdn !== '' ? $cdn : null;
			}
		}
		// Proxy sem token utilizável: não há como recuperar o arquivo
		if (str_contains($url, '/bunny-file')) {
			return null;
		}
		$path = self::pathFromPublicUrl($url);
		if ($path !== null) {
			$cdn = self::publicUrl($path);
			return $cdn !== '' ? $cdn : $url;
		}
		return $url;
	}

	/**
	 * URL para o cliente (React): proxy PHP com token (funciona com Token Auth na CDN).
	 * Gravação no banco continua via canonicalPublicUrl (CDN estável).
	 */
	public static function clientMediaUrl(?string $url, string $mediaKind = 'image'): ?string {
		$url = trim((string)$url);
		if ($url === '') {
			return null;
		}
		if (preg_match('#^https?://#i', $url) && str_contains($url, 'video.bunnycdn.com')) {
			return $url;
		}
		if (str_contains($url, '/playlist.m3u8')) {
			return $url;
		}
		$path = self::resolveInterativaPath($url);
		if ($path !== null) {
			return self::proxyUrlForPath($path);
		}
		if (str_contains($url, 'bunny-file')) {
			return self::proxyUrlForPublicUrl($url);
		}
		return preg_match('#^https?://#i', $url) ? $url : null;
	}

	/** Resolve path Storage interativa/ a partir de CDN, proxy ou path bruto. */
	public static function resolveInterativaPath(string $url): ?string {
		$url = trim($url);
		if ($url === '') {
			return null;
		}
		if (preg_match('#[?&]t=([^&]+)#', $url, $m)) {
			$p = self::pathFromFileToken(rawurldecode($m[1]), true);
			if ($p !== null) {
				return $p;
			}
		}
		$canonical = self::canonicalPublicUrl($url);
		if ($canonical !== null && $canonical !== '') {
			$url = $canonical;
		}
		$path = self::pathFromPublicUrl($url);
		if ($path !== null) {
			return $path;
		}
		$trim = self::normalizeStoragePath($url);
		if (self::isInterativaPath($trim)) {
			return $trim;
		}
		return null;
	}

	public static function proxyUrlForPublicUrl(string $publicUrl, int $ttlSec = 86400): string {
		$canonical = self::canonicalPublicUrl($publicUrl) ?? $publicUrl;
		$path = self::pathFromPublicUrl($canonical);
		if ($path === null) {
			$trim = self::normalizeStoragePath($canonical);
			if (self::isInterativaPath($trim)) {
				$path = $trim;
			}
		}
		if ($path === null) {
			return $publicUrl;
		}
		return self::proxyUrlForPath($path, $ttlSec);
	}

	public static function proxyUrlForPath(string $remotePath, int $ttlSec = 86400): string {
		$token = self::signFileToken($remotePath, $ttlSec);
		return rtrim((string)URL, '/').'/api/v1/student/bunny-file?t='.rawurlencode($token);
	}

	/**
	 * Baixa arquivo da Storage Zone via API (não depende da CDN/Token Auth).
	 * @return array{ok:bool,body?:string,contentType?:string,message?:string}
	 */
	public static function fetch(string $remotePath): array {
		$cfg = self::cfg();
		if (!$cfg->storagePronto()) {
			return ['ok' => false, 'message' => 'Bunny Storage não configurado.'];
		}
		$zone = trim((string)$cfg->storage_zone);
		$key = $cfg->getStorageAccessKey();
		$endpoint = trim((string)($cfg->storage_endpoint ?: 'storage.bunnycdn.com'));
		$endpoint = preg_replace('#^https?://#i', '', $endpoint);
		$endpoint = rtrim((string)$endpoint, '/');
		$remotePath = self::normalizeStoragePath($remotePath);
		if ($zone === '' || !$key || $remotePath === '') {
			return ['ok' => false, 'message' => 'Dados Storage incompletos.'];
		}
		$parts = array_map('rawurlencode', explode('/', $remotePath));
		$url = 'https://'.$endpoint.'/'.rawurlencode($zone).'/'.implode('/', $parts);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_HTTPGET => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_HTTPHEADER => [
				'AccessKey: '.$key,
				'Accept: */*',
			],
		]);
		$body = curl_exec($ch);
		$errno = curl_errno($ch);
		$err = curl_error($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		curl_close($ch);

		if ($errno) {
			return ['ok' => false, 'message' => 'cURL: '.$err];
		}
		if ($http < 200 || $http >= 300 || $body === false) {
			return ['ok' => false, 'message' => 'Storage HTTP '.$http];
		}
		$ext = strtolower(pathinfo($remotePath, PATHINFO_EXTENSION));
		$mime = self::mimeFromExtension($ext, $ctype !== '' ? preg_replace('/;.*$/', '', $ctype) : 'application/octet-stream');
		return ['ok' => true, 'body' => $body, 'contentType' => $mime];
	}

	/**
	 * @return array{ok:bool,message?:string}
	 */
	public static function delete(string $remotePath): array {
		$cfg = self::cfg();
		if (!$cfg->storagePronto()) {
			return ['ok' => true];
		}
		$zone = trim((string)$cfg->storage_zone);
		$key = $cfg->getStorageAccessKey();
		$endpoint = trim((string)($cfg->storage_endpoint ?: 'storage.bunnycdn.com'));
		$endpoint = preg_replace('#^https?://#i', '', $endpoint);
		$endpoint = rtrim((string)$endpoint, '/');
		$remotePath = ltrim(str_replace('\\', '/', $remotePath), '/');
		if ($zone === '' || !$key || $remotePath === '') {
			return ['ok' => true];
		}
		$parts = array_map('rawurlencode', explode('/', $remotePath));
		$url = 'https://'.$endpoint.'/'.rawurlencode($zone).'/'.implode('/', $parts);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST => 'DELETE',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 60,
			CURLOPT_HTTPHEADER => ['AccessKey: '.$key],
		]);
		curl_exec($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($http >= 200 && $http < 300 || $http === 404) {
			return ['ok' => true];
		}
		return ['ok' => false, 'message' => 'Falha ao excluir no Storage (HTTP '.$http.').'];
	}

	/**
	 * @return array{ok:bool,message?:string}
	 */
	public static function testar(): array {
		if (!PlataformaBunny::tabelaExiste()) {
			return ['ok' => false, 'message' => 'Execute database/plataforma_bunny.sql'];
		}
		$cfg = self::cfg();
		$zone = trim((string)$cfg->storage_zone);
		$key = $cfg->getStorageAccessKey();
		$endpoint = trim((string)($cfg->storage_endpoint ?: 'storage.bunnycdn.com'));
		$endpoint = preg_replace('#^https?://#i', '', $endpoint);
		$endpoint = rtrim((string)$endpoint, '/');
		if ($zone === '' || !$key) {
			return ['ok' => false, 'message' => 'Informe Storage Zone e Access Key no Master.'];
		}
		$url = 'https://'.$endpoint.'/'.rawurlencode($zone).'/';
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_HTTPGET => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTPHEADER => [
				'AccessKey: '.$key,
				'Accept: application/json',
			],
		]);
		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$err = curl_error($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($errno) {
			return ['ok' => false, 'message' => 'cURL: '.$err];
		}
		if ($http < 200 || $http >= 300) {
			return ['ok' => false, 'message' => 'Storage HTTP '.$http.($raw ? ': '.substr((string)$raw, 0, 120) : '')];
		}
		return ['ok' => true, 'message' => 'Conexão Storage OK (zone '.$zone.').'];
	}

	private static function tokenSecret(): string {
		$k = \App\Common\Environment::get('APP_KEY');
		if (!is_string($k) || trim($k) === '') {
			$k = \App\Common\Environment::get('SYSTEM_TOKEN');
		}
		$k = is_string($k) ? trim($k) : '';
		return $k !== '' ? $k : 'plataforma-bunny-media';
	}
}
