<?php 

namespace App\Http\Middleware;

class Maintenance{

	//EXECUTA O MIDDLEWARE
	public function handle($request, $next){

		//VERIFICA O ESTADO DE MANUTENÇÃO DA PÁGINA
		if(getenv('MAINTENANCE') == 'true'){
			throw new \Exception('Página em manutenção, tente outra vez mais tarde.', 503);
		}

		//EXECUTA O PROXIMO NIVEL DO MIDDLEWARE
		return $next($request);
	}

}