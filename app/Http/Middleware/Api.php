<?php 

namespace App\Http\Middleware;

class Api{

	//EXECUTA O MIDDLEWARE
	public function handle($request, $next){

		//ALTERA O CONTENT TYPE PARA JSON (header real + router)
		$request->getRouter()->setContentType('application/json');
		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8', true);
		}

		//EXECUTA O PROXIMO NIVEL DO MIDDLEWARE
		return $next($request);
	}

}