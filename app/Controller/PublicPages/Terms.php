<?php

namespace App\Controller\PublicPages;

use App\Utils\View;
use App\Common\Helpers\BrandingHelper;
use App\Controller\Admin\TermosDeUso;

class Terms {

	public static function index($request) {
		$urlBase = rtrim((string)URL, '/');
		$content = View::render('public/termos', [
			'logo_url' => BrandingHelper::urlLogoXd360(),
			'versao' => TermosDeUso::VERSAO,
			'data_versao' => TermosDeUso::DATA_VERSAO,
			'contato_email' => 'contato@xd360.com.br',
			'url_privacidade' => $urlBase.'/privacidade',
			'url_exclusao' => $urlBase.'/exclusao-de-dados',
			'URL' => $urlBase,
		]);

		return View::render('login/page', [
			'title' => 'Termos de Uso — XD360',
			'content' => $content,
			'favicon_url' => BrandingHelper::urlFaviconXd360(),
		]);
	}
}
