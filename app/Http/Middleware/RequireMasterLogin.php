<?php

namespace App\Http\Middleware;

use App\Session\User\Login as SessionAdminLogin;
use App\Common\Helpers\MasterGateHelper;

class RequireMasterLogin {

	public function handle($request, $next) {
		if (!SessionAdminLogin::isUserLogged()) {
			$request->getRouter()->redirect('/');
		}

		if (!SessionAdminLogin::syncSessionFromDatabase()) {
			$request->getRouter()->redirect('/');
		}

		if (!MasterGateHelper::isMasterSession()) {
			$request->getRouter()->redirect('/painel');
		}

		return $next($request);
	}
}
