<?php 

namespace App\Http\Middleware;
use \App\Model\Entity\User;

class UserBasicAuth{

	private function getBasicAuthUser(){
	//VERIFICA A EXISTENCIA DOS DADOS DE ACESSO
		if(!isset($_SERVER['PHP_AUTH_USER']) or !isset($_SERVER['PHP_AUTH_PW'])){
			return false;
		}

		//BUSCA O USUÁRIO PELO EMAIL
		$obUser = User::getUserByEmail($_SERVER['PHP_AUTH_USER']);

		//VERIFICA A INSTANCIA
		if(!$obUser instanceof User){
			return false;
		}

		//VERIFICA SENHA
		return password_verify($_SERVER['PHP_AUTH_PW'], $obUser->senha) ? $obUser : false;

		//SUCESSO
		return true;
}

	//RESPONSÁVEL POR VALIDAR O ACESSO VIA HTTP BASIC AUTH
	private function basicAuth($request){

		//VERIFICA O USUÁRIO RECEBIDO
		if($obUser= $this->getBasicAuthUser()){
			$request->user = $obUser;
			return true;
		}
		throw new \Exception('Credenciais invalidas!', 403);

	}

	//EXECUTA O MIDDLEWARE
	public function handle($request, $next){
		//REALIZA A VALIDAÇÃO DO ACESSO VIA BASIC AUTH
		$this->basicAuth($request);

		//EXECUTA O PROXIMO NIVEL DO MIDDLEWARE
		return $next($request);
	}

}