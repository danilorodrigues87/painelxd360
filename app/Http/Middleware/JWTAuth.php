<?php 

namespace App\Http\Middleware;
use \App\Model\Entity\User;
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

class JWTAuth{

	private function getJWTAuthUser($request){
		//RECEBE OS HEADERS
		$headers = $request->getHeaders();
		$jwt = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : '';
		
		try {
			//DECODE
		$decode = (array)JWT::decode($jwt,new key(getenv('JWT_KEY'),'HS256'));
		} catch (\Exception $e) {
			throw new \Exception("Token inválido",400);
		}
		

		//EMAIL
		$email = $decode['email'] ?? '';

		//BUSCA O USUÁRIO PELO EMAIL
		$obUser = User::getUserByEmail($email);

		//RETORNA O USUÁRIO
		return $obUser instanceof User ? $obUser : false;

}

	//RESPONSÁVEL POR VALIDAR O ACESSO VIA JWT
	private function auth($request){

		//VERIFICA O USUÁRIO RECEBIDO
		if($obUser= $this->getJWTAuthUser($request)){
			$request->user = $obUser;
			return true;
		}
		throw new \Exception('Acesso negado', 403);

	}

	//EXECUTA O MIDDLEWARE
	public function handle($request, $next){
		//REALIZA A VALIDAÇÃO DO ACESSO JWT
		$this->auth($request);

		//EXECUTA O PROXIMO NIVEL DO MIDDLEWARE
		return $next($request);
	}

}