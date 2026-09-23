<?php

namespace App\Controller\Master;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Model\Entity\User as EntityUser;
use App\Common\Helpers\UserFotoHelper;
use App\Common\Helpers\BrandingHelper;
use App\Common\Helpers\MasterMenuHelper;

class Page {

	public static function getPanel(string $title, string $content, string $menuAtivo = 'home'): string {
		$user = SessionUser::getUserLogedData();
		$nome = $user['usuario']['nome'] ?? 'Master';
		$uid = (int)($user['usuario']['id'] ?? 0);
		$fotoUrl = UserFotoHelper::urlPadrao();
		if ($uid > 0 && EntityUser::temColunaFoto()) {
			$ob = EntityUser::getUser('id = '.$uid, null, 1, 'foto')->fetchObject(EntityUser::class);
			$fotoUrl = UserFotoHelper::urlPublica($ob->foto ?? null);
		}

		$menu = View::render('master/panel', [
			'user'       => $nome,
			'menu_links' => MasterMenuHelper::render($menuAtivo),
		]);

		return View::render('master/page', [
			'title'    => $title,
			'content'  => $content,
			'menu'     => $menu,
			'user'     => $nome,
			'foto_url' => $fotoUrl,
			'logo_url' => BrandingHelper::urlLogoXd360(true),
			'favicon_url' => BrandingHelper::urlFaviconXd360(),
		]);
	}
}
