<?php

namespace App\Common\Helpers;

/**
 * Mídias sociais em uploads/social/{id_admin}/ — URL pública para a Meta fazer cURL.
 */
class SocialMediaStorage {

	private const MAX_IMAGE = 8388608;   // 8 MB
	private const MAX_VIDEO = 104857600; // 100 MB

	public static function dirBase(): string {
		$root = rtrim(str_replace('\\', '/', realpath(__DIR__.'/../../../') ?: (__DIR__.'/../../..')), '/');
		return $root.'/uploads/social';
	}

	public static function dirEscola(int $idAdmin): string {
		return self::dirBase().'/'.(int)$idAdmin;
	}

	/**
	 * @return array{relative:string,url:string,mime:string,bytes:int,tipo:string}|null
	 */
	public static function salvarUpload(int $idAdmin, array $file): ?array {
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return null;
		}
		$tmp = (string)($file['tmp_name'] ?? '');
		if ($tmp === '' || !is_uploaded_file($tmp)) {
			return null;
		}
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$mime = (string)($finfo->file($tmp) ?: ($file['type'] ?? ''));
		$tipo = strpos($mime, 'video/') === 0 ? 'video' : (
			strpos($mime, 'image/') === 0 ? 'image' : ''
		);
		if ($tipo === '') {
			return null;
		}
		$size = (int)($file['size'] ?? 0);
		$max = $tipo === 'video' ? self::MAX_VIDEO : self::MAX_IMAGE;
		if ($size <= 0 || $size > $max) {
			return null;
		}
		$bin = file_get_contents($tmp);
		if ($bin === false) {
			return null;
		}
		$ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
		$ext = preg_replace('/[^a-z0-9]/', '', $ext ?: ($tipo === 'video' ? 'mp4' : 'jpg'));
		return self::gravarBytes($idAdmin, $bin, $ext, $mime, $tipo);
	}

	/**
	 * @return array{relative:string,url:string,mime:string,bytes:int,tipo:string}|null
	 */
	public static function gravarBytes(int $idAdmin, string $bin, string $ext, string $mime, string $tipo): ?array {
		$dir = self::dirEscola($idAdmin);
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			return null;
		}
		$name = date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
		$abs = $dir.'/'.$name;
		if (file_put_contents($abs, $bin) === false) {
			return null;
		}
		$relative = 'uploads/social/'.(int)$idAdmin.'/'.$name;
		return [
			'relative' => $relative,
			'url' => self::urlPublica($relative),
			'mime' => $mime,
			'bytes' => strlen($bin),
			'tipo' => $tipo,
		];
	}

	public static function urlPublica(string $relative): string {
		$relative = ltrim(str_replace('\\', '/', $relative), '/');
		return rtrim((string)URL, '/').'/'.$relative;
	}

	public static function caminhoAbsoluto(string $relative): string {
		$relative = ltrim(str_replace('\\', '/', $relative), '/');
		$root = rtrim(str_replace('\\', '/', realpath(__DIR__.'/../../../') ?: (__DIR__.'/../../..')), '/');
		return $root.'/'.$relative;
	}

	public static function apagar(?string $relative): void {
		if ($relative === null || $relative === '') {
			return;
		}
		$abs = self::caminhoAbsoluto($relative);
		if (is_file($abs)) {
			@unlink($abs);
		}
	}

	/** Cópia do arquivo para outro post do lote (evita 404 após purge do primeiro). */
	public static function copiarParaEscola(int $idAdmin, string $relativeOrigem): ?array {
		$abs = self::caminhoAbsoluto($relativeOrigem);
		if (!is_file($abs)) {
			return null;
		}
		$bin = file_get_contents($abs);
		if ($bin === false) {
			return null;
		}
		$ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'jpg';
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$mime = (string)($finfo->file($abs) ?: 'application/octet-stream');
		$tipo = strpos($mime, 'video/') === 0 ? 'video' : 'image';
		return self::gravarBytes($idAdmin, $bin, $ext, $mime, $tipo);
	}
}
