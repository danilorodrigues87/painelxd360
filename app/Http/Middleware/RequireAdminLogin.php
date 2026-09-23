<?php 

namespace App\Http\Middleware;

use \App\Session\User\Login as SessionAdminLogin;
use App\Common\Helpers\MasterGateHelper;
use App\Common\Helpers\TenantContext;

class RequireAdminLogin{

	public function handle($request, $next){

		//VERIFICA SE O USUÁRIO ESTÁ LOGADO
		if(!SessionAdminLogin::isUserLogged()){
			$request->getRouter()->redirect('/');
		}

		//ATUALIZA PERMISSÕES E STATUS DA SESSÃO
		if(!SessionAdminLogin::syncSessionFromDatabase()){
			$request->getRouter()->redirect('/');
		}

		if (TenantContext::hasTenant()) {
			$tenantId = (int)TenantContext::getIdAdmin();
			$user = SessionAdminLogin::getUserLogedData();
			if ($user === null) {
				SessionAdminLogin::logout();
				$request->getRouter()->redirect('/');
			}
			$userAdmin = (int)($user['usuario']['id_admin'] ?? 0);
			$isMaster = MasterGateHelper::isMasterSession();
			$devSim = TenantContext::mode() === 'subdominio_dev';
			if (!$devSim && !$isMaster && !SessionAdminLogin::isImpersonating() && $userAdmin !== $tenantId) {
				SessionAdminLogin::logout();
				$request->getRouter()->redirect('/');
			}
		}

		// Escola suspensa: só Assinatura (+ logout)
		if (SessionAdminLogin::isAssinaturaBloqueada()) {
			$uri = (string)$request->getUri();
			if (!SessionAdminLogin::uriPermitidaQuandoBloqueada($uri)) {
				$request->getRouter()->redirect('/painel/assinatura');
			}
		}

		//CONTINUA A EXECUÇÃO
		return $next($request);
		
	}
}