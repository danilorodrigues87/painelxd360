<?php

namespace App\Common\Communication;

/**
 * Armazena mídias de WhatsApp em uploads/whatsapp/{id_admin}/...
 */
class WhatsappMediaStorage {

	private const MAX_BYTES = 15728640; // 15 MB

	public static function dirBase(): string {
		return rtrim(str_replace('\\', '/', realpath(__DIR__.'/../../../') ?: (__DIR__.'/../../..')), '/')
			.'/uploads/whatsapp';
	}

	/**
	 * @return array{relative:string,url:string,mimetype:?string}|null
	 */
	public static function salvarBase64(int $idAdmin, string $base64, string $tipo = 'bin', ?string $mimetype = null): ?array {
		$base64 = trim($base64);
		if ($base64 === '') {
			return null;
		}

		if (preg_match('#^data:([^;]+);base64,(.+)$#s', $base64, $m)) {
			$mimetype = $mimetype ?: $m[1];
			$base64 = $m[2];
		}

		$bin = base64_decode($base64, true);
		if ($bin === false || $bin === '') {
			return null;
		}
		if (strlen($bin) > self::MAX_BYTES) {
			return null;
		}

		$ext = self::extensaoPorMime($mimetype, $tipo);
		return self::gravarBytes($idAdmin, $bin, $ext, $mimetype);
	}

	/**
	 * @return array{relative:string,url:string,mimetype:?string}|null
	 */
	public static function salvarUpload(int $idAdmin, array $file): ?array {
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return null;
		}
		$tmp = $file['tmp_name'] ?? '';
		if ($tmp === '' || !is_uploaded_file($tmp)) {
			return null;
		}
		$size = (int)($file['size'] ?? 0);
		if ($size <= 0 || $size > self::MAX_BYTES) {
			return null;
		}

		$mimetype = $file['type'] ?? null;
		$finfo = new \finfo(FILEINFO_MIME_TYPE);
		$detected = $finfo->file($tmp);
		if (is_string($detected) && $detected !== '') {
			$mimetype = $detected;
		}

		$origExt = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
		$tipo = strpos((string)$mimetype, 'audio/') === 0 ? 'audio' : (
			strpos((string)$mimetype, 'image/') === 0 ? 'image' : 'bin'
		);
		$ext = $origExt !== '' ? preg_replace('/[^a-z0-9]/', '', $origExt) : self::extensaoPorMime($mimetype, $tipo);
		$bin = file_get_contents($tmp);
		if ($bin === false) {
			return null;
		}
		return self::gravarBytes($idAdmin, $bin, $ext ?: 'bin', $mimetype);
	}

	public static function urlPublica(string $relative): string {
		$relative = ltrim(str_replace('\\', '/', $relative), '/');
		return rtrim((string)URL, '/').'/'.$relative;
	}

	/**
	 * @return array{relative:string,url:string,mimetype:?string}|null
	 */
	private static function gravarBytes(int $idAdmin, string $bin, string $ext, ?string $mimetype): ?array {
		$ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext)) ?: 'bin';
		$subdir = (int)$idAdmin.'/'.date('Y').'/'.date('m');
		$absDir = self::dirBase().'/'.$subdir;
		if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
			return null;
		}

		$nome = date('YmdHis').'-'.bin2hex(random_bytes(4)).'.'.$ext;
		$abs = $absDir.'/'.$nome;
		if (file_put_contents($abs, $bin) === false) {
			return null;
		}

		$relative = 'uploads/whatsapp/'.$subdir.'/'.$nome;
		return [
			'relative' => $relative,
			'url'      => self::urlPublica($relative),
			'mimetype' => $mimetype,
		];
	}

	private static function extensaoPorMime(?string $mime, string $tipo): string {
		$map = [
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
			'audio/ogg'  => 'ogg',
			'audio/opus' => 'ogg',
			'audio/mpeg' => 'mp3',
			'audio/mp4'  => 'm4a',
			'audio/aac'  => 'aac',
			'audio/webm' => 'webm',
			'audio/wav'  => 'wav',
			'video/mp4'  => 'mp4',
			'application/pdf' => 'pdf',
			'application/msword' => 'doc',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
			'application/vnd.ms-excel' => 'xls',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
			'text/plain' => 'txt',
			'application/zip' => 'zip',
		];
		if ($mime && isset($map[$mime])) {
			return $map[$mime];
		}
		if ($tipo === 'image') {
			return 'jpg';
		}
		if ($tipo === 'audio') {
			return 'ogg';
		}
		if ($tipo === 'document') {
			return 'pdf';
		}
		return 'bin';
	}
}
