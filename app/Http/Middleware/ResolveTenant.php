<?php

namespace App\Http\Middleware;

use App\Common\Helpers\TenantContext;
use App\Common\Helpers\TenantHostHelper;

class ResolveTenant {

	public function handle($request, $next) {
		TenantHostHelper::bootstrap();

		$uri = (string)$request->getUri();
		$isMaster = ($uri === '/master' || str_starts_with($uri, '/master/'));

		if ($isMaster && TenantContext::isTenantHost()) {
			$request->getRouter()->redirect(TenantHostHelper::masterUrl().'/master');
		}

		if (TenantContext::mode() === 'unknown_subdominio') {
			http_response_code(404);
			echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="utf-8"><title>Cliente não encontrado — XD360</title></head>'
				.'<body style="font-family:sans-serif;text-align:center;padding:4rem">'
				.'<h1>Cliente não encontrado</h1>'
				.'<p>Este subdomínio não está cadastrado na XD360.</p>'
				.'</body></html>';
			exit;
		}

		return $next($request);
	}
}
