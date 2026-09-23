<?php

namespace App\Controller\Master;

use App\Session\User\Login as SessionUser;

class Impersonate {

	/** Volta da escola para o Painel Master (sessão restaurada). */
	public static function voltar($request) {
		if (!SessionUser::encerrarImpersonate()) {
			$request->getRouter()->redirect('/painel');
		}
		$request->getRouter()->redirect('/master/escolas');
	}
}
