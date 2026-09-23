<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;

/**
 * Bunny passou a ser global no Master. Esta rota só informa a escola.
 */
class ConfigBunny extends Page {

	public static function index($request) {
		$user = SessionUser::getUserLogedData();
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor') {
			$request->getRouter()->redirect('/painel');
			return '';
		}
		$content = View::render('admin/modules/config/bunny-removido', []);
		return parent::getPanel('Bunny Stream', $content, 'config', $request);
	}

	public static function getInfo($request) {
		return json_encode([
			'success' => false,
			'message' => 'As credenciais Bunny são gerenciadas no Painel Master (conta única para todas as escolas).',
		], JSON_UNESCAPED_UNICODE);
	}
}
