<?php

namespace App\Common\Helpers;

use App\Common\Upload;

class UserFotoHelper {

	public const DIR_RELATIVO = '/img/usuarios/';
	public const PADRAO = 'resources/assets/img/icons/client.png';

	public static function urlPadrao(): string {
		return rtrim((string)URL, '/').'/'.self::PADRAO;
	}

	public static function urlPublica(?string $foto): string {
		$foto = trim((string)$foto);
		if ($foto === '' || strpos($foto, '..') !== false || strpos($foto, '/') !== false) {
			return self::urlPadrao();
		}
		return rtrim((string)URL, '/').'/uploads'.self::DIR_RELATIVO.$foto;
	}

	/** Caminho absoluto no disco (basename validado). */
	public static function caminhoAbsoluto(?string $foto): ?string {
		$foto = trim((string)$foto);
		if ($foto === '' || strpos($foto, '..') !== false || strpos($foto, '/') !== false) {
			return null;
		}
		$path = __DIR__.'/../../uploads'.self::DIR_RELATIVO.$foto;
		return is_file($path) ? $path : null;
	}

	/**
	 * Processa upload de foto. Retorna o basename novo ou o valor atual se não houver arquivo.
	 */
	public static function processarUpload(?array $file, ?string $fotoAtual = null): ?string {
		$fotoAtual = trim((string)$fotoAtual);
		if ($fotoAtual === '') {
			$fotoAtual = null;
		}

		if (!is_array($file) || empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return $fotoAtual;
		}

		$type = strtolower((string)($file['type'] ?? ''));
		if (strpos($type, 'image/') !== 0) {
			return $fotoAtual;
		}

		$size = (int)($file['size'] ?? 0);
		if ($size <= 0 || $size > 5 * 1024 * 1024) {
			return $fotoAtual;
		}

		$obUpload = new Upload($file);
		$obUpload->generateNewName();
		$ok = $obUpload->upload(self::DIR_RELATIVO, false, $fotoAtual);
		if (!$ok) {
			return $fotoAtual;
		}

		return $obUpload->getBasename();
	}

	/** Bloco HTML de preview + input para formulários (modal ou página). */
	public static function htmlCampoFormulario(?string $fotoAtual = null, string $inputId = 'input-foto-user'): string {
		$url = self::urlPublica($fotoAtual);
		$atual = htmlspecialchars((string)$fotoAtual, ENT_QUOTES, 'UTF-8');
		$id = htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8');
		$previewId = 'preview-'.$id;

		return '
		<div class="form-group col-12 mb-3 text-center">
			<label class="d-block mb-2">Foto</label>
			<img id="'.$previewId.'" src="'.$url.'" alt="Foto"
				style="width:110px;height:110px;object-fit:cover;border-radius:50%;border:2px solid #dee2e6;">
			<div class="mt-2 mx-auto" style="max-width:320px;">
				<input type="file" name="foto" id="'.$id.'" class="form-control form-control-sm" accept="image/*"
					onchange="displaySelectedImage(event,\''.$previewId.'\')">
			</div>
			<input type="hidden" name="foto_atual" value="'.$atual.'">
			<div class="form-text">JPG, PNG ou WEBP · máx. 5 MB. Sem foto usa a imagem padrão.</div>
		</div>';
	}
}
