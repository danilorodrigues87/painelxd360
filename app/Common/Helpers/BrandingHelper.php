<?php

namespace App\Common\Helpers;

use App\Common\Upload;

/**
 * Identidade visual XD360 (UI) vs logo do cliente (impressos/branding).
 */
class BrandingHelper {

	public const LOGO_XD360 = 'resources/assets/img/brand/xd360-logo.svg';
	public const LOGO_XD360_DARK = 'resources/assets/img/brand/xd360-logo-dark.svg';
	public const ICONE_XD360 = 'resources/assets/img/brand/xd360-icon.svg';
	/** @deprecated use LOGO_XD360 */
	public const LOGO_CTI = self::LOGO_XD360;
	/** @deprecated use ICONE_XD360 */
	public const ICONE_CTI = self::ICONE_XD360;
	public const DIR_ESCOLAS = '/img/escolas/';
	public const DIR_MODELO_CERT = '/img/certificado/modelos/';
	public const DIR_CONQUISTAS = '/img/conquistas/';
	public const DIR_PORTAL = '/img/portal/';
	public const DIR_CONECT = '/img/conect/';
	public const DIR_SITE_B2B = '/img/site-b2b/';
	public const DIR_CONECT_EMPRESAS = '/img/conect/empresas/';
	public const DIR_CONECT_BLOG = '/img/conect/blog/';
	public const DIR_CONECT_DEPOIMENTOS = '/img/conect/depoimentos/';
	public const DIR_CONECT_ANUNCIOS = '/img/conect/anuncios/';
	public const MODELO_CERT_PADRAO = 'uploads/img/certificado/modelo_cert.png';

	public static function urlBase(): string {
		return rtrim((string)URL, '/');
	}

	public static function urlLogoXd360(bool $darkNav = false): string {
		$path = $darkNav ? self::LOGO_XD360_DARK : self::LOGO_XD360;
		return self::urlBase().'/'.$path;
	}

	public static function urlFaviconXd360(): string {
		return self::urlBase().'/'.self::ICONE_XD360;
	}

	/** @deprecated use urlLogoXd360() */
	public static function urlLogoCti(): string {
		return self::urlLogoXd360();
	}

	/** @deprecated use urlFaviconXd360() */
	public static function urlFaviconCti(): string {
		return self::urlFaviconXd360();
	}

	/** Logo enviada no Master (Portal do aluno), se existir. */
	public static function urlLogoPortalAtual(): ?string {
		static $cache = false;
		static $url = null;
		if ($cache !== false) {
			return $url;
		}
		$cache = true;
		try {
			if (!class_exists(\App\Model\Entity\PortalAlunoBranding::class)) {
				return null;
			}
			if (!\App\Model\Entity\PortalAlunoBranding::tabelasExistem()) {
				return null;
			}
			$row = \App\Model\Entity\PortalAlunoBranding::get();
			$url = self::urlPortalLogo($row->logo ?? null);
		} catch (\Throwable $e) {
			$url = null;
		}
		return $url;
	}

	public static function urlModeloCertPadrao(): string {
		return self::urlBase().'/'.self::MODELO_CERT_PADRAO;
	}

	/**
	 * Logo da escola para contrato e recibo.
	 * Aceita basename em uploads/img/escolas/ ou legado em icons/.
	 * Sem logo válida, usa a logo XD360.
	 */
	public static function urlLogoEscola(?string $logo): string {
		$logo = trim((string)$logo);
		if ($logo === '' || strpos($logo, '..') !== false || strpos($logo, '/') !== false || strpos($logo, '\\') !== false) {
			return self::urlLogoCti();
		}

		$raiz = realpath(__DIR__.'/../../../');
		if ($raiz === false) {
			return self::urlLogoCti();
		}

		$uploadFs = $raiz.DIRECTORY_SEPARATOR.'uploads'.str_replace('/', DIRECTORY_SEPARATOR, self::DIR_ESCOLAS).$logo;
		if (is_file($uploadFs)) {
			return self::urlBase().'/uploads'.self::DIR_ESCOLAS.$logo;
		}

		$iconsFs = $raiz.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'img'.DIRECTORY_SEPARATOR.'icons'.DIRECTORY_SEPARATOR.$logo;
		if (is_file($iconsFs)) {
			return self::urlBase().'/resources/assets/img/icons/'.$logo;
		}

		return self::urlLogoCti();
	}

	/**
	 * Fundo do certificado da escola (imagem A4 paisagem já com logo).
	 * Sem modelo da escola, usa o padrão global.
	 */
	public static function urlModeloCertificado(?string $arquivo): string {
		$arquivo = trim((string)$arquivo);
		if ($arquivo === '' || strpos($arquivo, '..') !== false || strpos($arquivo, '/') !== false || strpos($arquivo, '\\') !== false) {
			return self::urlModeloCertPadrao();
		}

		$raiz = realpath(__DIR__.'/../../../');
		if ($raiz === false) {
			return self::urlModeloCertPadrao();
		}

		$fs = $raiz.DIRECTORY_SEPARATOR.'uploads'.str_replace('/', DIRECTORY_SEPARATOR, self::DIR_MODELO_CERT).$arquivo;
		if (is_file($fs)) {
			return self::urlBase().'/uploads'.self::DIR_MODELO_CERT.$arquivo;
		}

		return self::urlModeloCertPadrao();
	}

	/**
	 * Processa upload da logo da escola. Retorna basename novo ou o atual.
	 */
	public static function processarUploadLogo(?array $file, ?string $logoAtual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_ESCOLAS, $logoAtual, 5 * 1024 * 1024);
	}

	/**
	 * Processa upload do modelo de certificado (PNG/JPG, até 8 MB).
	 */
	public static function processarUploadModeloCertificado(?array $file, ?string $atual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_MODELO_CERT, $atual, 8 * 1024 * 1024);
	}

	/** Upload de figurinha/medalha de conquista (PNG/JPG/WebP, até 2 MB). */
	public static function processarUploadBadgeConquista(?array $file, ?string $atual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_CONQUISTAS, $atual, 2 * 1024 * 1024);
	}

	/** URL pública do badge; null se inválido/ausente. */
	public static function urlBadgeConquista(?string $arquivo): ?string {
		$arquivo = trim((string)$arquivo);
		if ($arquivo === '' || strpos($arquivo, '..') !== false || strpos($arquivo, '/') !== false || strpos($arquivo, '\\') !== false) {
			return null;
		}
		$raiz = realpath(__DIR__.'/../../../');
		if ($raiz === false) {
			return null;
		}
		$fs = $raiz.DIRECTORY_SEPARATOR.'uploads'.str_replace('/', DIRECTORY_SEPARATOR, self::DIR_CONQUISTAS).$arquivo;
		if (!is_file($fs)) {
			return null;
		}
		return self::urlBase().'/uploads'.self::DIR_CONQUISTAS.$arquivo;
	}

	public static function processarUploadPortalLogo(?array $file, ?string $atual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_PORTAL, $atual, 2 * 1024 * 1024);
	}

	public static function processarUploadPortalLoginHero(?array $file, ?string $atual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_PORTAL, $atual, 5 * 1024 * 1024);
	}

	/** URL pública da logo do portal do aluno; null se inválido/ausente. */
	public static function urlPortalLogo(?string $arquivo): ?string {
		return self::urlPortalArquivo($arquivo);
	}

	/** URL pública do fundo do login do portal; null se inválido/ausente. */
	public static function urlPortalLoginHero(?string $arquivo): ?string {
		return self::urlPortalArquivo($arquivo);
	}

	private static function urlPortalArquivo(?string $arquivo): ?string {
		return self::urlUploadArquivo($arquivo, self::DIR_PORTAL);
	}

	public static function processarUploadConectLogo(?array $file, ?string $atual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_CONECT, $atual, 2 * 1024 * 1024);
	}

	public static function processarUploadConectHero(?array $file, ?string $atual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_CONECT, $atual, 5 * 1024 * 1024);
	}

	public static function urlConectLogo(?string $arquivo): ?string {
		return self::urlUploadArquivo($arquivo, self::DIR_CONECT);
	}

	public static function urlConectHero(?string $arquivo): ?string {
		return self::urlUploadArquivo($arquivo, self::DIR_CONECT);
	}

	public static function processarUploadSiteB2bLogo(?array $file, ?string $atual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_SITE_B2B, $atual, 2 * 1024 * 1024);
	}

	public static function processarUploadSiteB2bHero(?array $file, ?string $atual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_SITE_B2B, $atual, 5 * 1024 * 1024);
	}

	public static function urlSiteB2bLogo(?string $arquivo): ?string {
		return self::urlUploadArquivo($arquivo, self::DIR_SITE_B2B);
	}

	public static function urlSiteB2bHero(?string $arquivo): ?string {
		return self::urlUploadArquivo($arquivo, self::DIR_SITE_B2B);
	}

	public static function processarUploadConectEmpresaLogo(?array $file, ?string $atual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_CONECT_EMPRESAS, $atual, 2 * 1024 * 1024);
	}

	public static function urlConectEmpresaLogo(?string $arquivo): ?string {
		return self::urlUploadArquivo($arquivo, self::DIR_CONECT_EMPRESAS);
	}

	public static function processarUploadConectBlogImagem(?array $file, ?string $atual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_CONECT_BLOG, $atual, 5 * 1024 * 1024);
	}

	public static function urlConectBlogImagem(?string $arquivo): ?string {
		return self::urlUploadArquivo($arquivo, self::DIR_CONECT_BLOG);
	}

	public static function processarUploadConectDepoimentoAvatar(?array $file, ?string $atual = null): ?string {
		return self::processarUploadImagem($file, self::DIR_CONECT_DEPOIMENTOS, $atual, 2 * 1024 * 1024);
	}

	public static function urlConectDepoimentoAvatar(?string $arquivo): ?string {
		return self::urlUploadArquivo($arquivo, self::DIR_CONECT_DEPOIMENTOS);
	}

	public static function processarUploadConectAnuncio(?array $file, ?string $atual = null): ?string {
		$res = self::processarUploadImagemDetalhe($file, self::DIR_CONECT_ANUNCIOS, $atual, 3 * 1024 * 1024);
		return $res['filename'];
	}

	/** @return array{filename:?string,error:?string} */
	public static function processarUploadConectAnuncioDetalhe(?array $file, ?string $atual = null): array {
		return self::processarUploadImagemDetalhe($file, self::DIR_CONECT_ANUNCIOS, $atual, 3 * 1024 * 1024);
	}

	private static function arquivoImagemValido(array $file): bool {
		$ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
		if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
			return true;
		}
		$type = strtolower((string)($file['type'] ?? ''));
		if ($type !== '' && strpos($type, 'image/') === 0) {
			return true;
		}
		$tmp = (string)($file['tmp_name'] ?? '');
		if ($tmp !== '' && is_file($tmp) && function_exists('finfo_open')) {
			$fi = finfo_open(FILEINFO_MIME_TYPE);
			if ($fi) {
				$detected = finfo_file($fi, $tmp);
				finfo_close($fi);
				if (is_string($detected) && strpos($detected, 'image/') === 0) {
					return true;
				}
			}
		}
		return false;
	}

	/** @return array{filename:?string,error:?string} */
	private static function processarUploadImagemDetalhe(?array $file, string $dirRelativo, ?string $atual, int $maxBytes): array {
		$atual = trim((string)$atual);
		if ($atual === '') {
			$atual = null;
		}

		if (!is_array($file) || empty($file['name'])) {
			return ['filename' => $atual, 'error' => null];
		}

		$err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($err === UPLOAD_ERR_NO_FILE) {
			return ['filename' => $atual, 'error' => null];
		}
		if ($err !== UPLOAD_ERR_OK) {
			$maxMb = round($maxBytes / 1024 / 1024, 1);
			$msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
				? 'Imagem muito grande. Máximo '.$maxMb.' MB.'
				: 'Falha no envio da imagem (código '.$err.').';
			return ['filename' => $atual, 'error' => $msg];
		}

		if (!self::arquivoImagemValido($file)) {
			return ['filename' => $atual, 'error' => 'Formato inválido. Use JPG, PNG ou WebP.'];
		}

		$size = (int)($file['size'] ?? 0);
		if ($size <= 0 || $size > $maxBytes) {
			$maxMb = round($maxBytes / 1024 / 1024, 1);
			return ['filename' => $atual, 'error' => 'Imagem muito grande. Máximo '.$maxMb.' MB.'];
		}

		$obUpload = new Upload($file);
		$obUpload->generateNewName();
		$ok = $obUpload->upload($dirRelativo, false, $atual);
		if (!$ok) {
			return ['filename' => $atual, 'error' => 'Não foi possível salvar a imagem. Verifique permissões da pasta uploads.'];
		}

		return ['filename' => $obUpload->getBasename(), 'error' => null];
	}

	private static function processarUploadImagem(?array $file, string $dirRelativo, ?string $atual, int $maxBytes): ?string {
		$res = self::processarUploadImagemDetalhe($file, $dirRelativo, $atual, $maxBytes);
		return $res['filename'];
	}

	public static function urlConectAnuncioImagem(?string $arquivo): ?string {
		return self::urlUploadArquivo($arquivo, self::DIR_CONECT_ANUNCIOS);
	}

	private static function urlUploadArquivo(?string $arquivo, string $dirRelativo): ?string {
		$arquivo = trim((string)$arquivo);
		if ($arquivo === '' || strpos($arquivo, '..') !== false || strpos($arquivo, '/') !== false || strpos($arquivo, '\\') !== false) {
			return null;
		}
		$raiz = realpath(__DIR__.'/../../../');
		if ($raiz === false) {
			return null;
		}
		$fs = $raiz.DIRECTORY_SEPARATOR.'uploads'.str_replace('/', DIRECTORY_SEPARATOR, $dirRelativo).$arquivo;
		if (!is_file($fs)) {
			return null;
		}
		return self::urlBase().'/uploads'.$dirRelativo.$arquivo;
	}

	/** HTML do rodapé padrão XD360. */
	public static function footerHtml(): string {
		return '<footer class="py-4 xd360-footer text-white mt-auto">'
			.'<div class="container-fluid px-4">'
			.'<div class="small text-muted d-flex flex-wrap gap-2 justify-content-between align-items-center">'
			.'<span>&copy; XD360</span>'
			.'<span>Desenvolvido por <a class="text-muted text-decoration-none" target="_blank" rel="noopener noreferrer" href="https://xdtec.com.br">XDTEC</a></span>'
			.'</div></div></footer>';
	}
}
