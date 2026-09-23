<?php 

namespace App\Http\Middleware;

use \App\Session\User\Login as SessionAdminLogin;
use \App\Common\Helpers\MasterGateHelper;

class RequireAdminLogout{

	public function handle($request, $next){

		//VERIFICA SE O USUÁRIO ESTÁ LOGADO
		if (SessionAdminLogin::isUserLogged()) {
			if (!SessionAdminLogin::syncSessionFromDatabase()) {
				SessionAdminLogin::logout();
				return $next($request);
			}
			if (MasterGateHelper::isMasterSession()) {
				$request->getRouter()->redirect('/master');
			}
			$request->getRouter()->redirect('/painel');
		}

		//CONTINUA A EXECUÇÃO
		return $next($request);
		
	}
}