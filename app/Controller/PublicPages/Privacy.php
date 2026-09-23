<?php

namespace App\Controller\PublicPages;

use App\Utils\View;
use App\Common\Helpers\BrandingHelper;

/**
 * Páginas públicas (sem login) — exigidas pelo Meta App Review etc.
 */
class Privacy {

	public static function index($request) {
		$logoUrl = BrandingHelper::urlLogoCti();
		$faviconUrl = BrandingHelper::urlFaviconCti();
		$atualizado = '27 de julho de 2026';
		$contato = 'ctieducacional@gmail.com';
		$site = 'https://ctieducacional.com.br';
		$urlPolitica = rtrim((string)URL, '/').'/privacidade';

		$content = View::render('public/privacy', [
			'logo_url' => $logoUrl,
			'atualizado' => $atualizado,
			'contato_email' => $contato,
			'site_cti' => $site,
			'url_politica' => $urlPolitica,
			'URL' => rtrim((string)URL, '/'),
		]);

		return View::render('login/page', [
			'title' => 'Política de Privacidade — CTI Educacional',
			'content' => $content,
			'favicon_url' => $faviconUrl,
		]);
	}
}
