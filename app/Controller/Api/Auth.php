<?php 

namespace App\Controller\Api;
use \App\Model\Entity\User;
use \Firebase\JWT\JWT;

class Auth extends Api{

	//RESPOMSÁVEL POR GERAR UM TOKEN JWT
	public static function generateToken($request){
		$postVars = $request->getPostVars();

		//VALIDA OS CAMPOS OBRIGÁTORIOS
		if(!isset($postVars['email']) or !isset($postVars['senha'])){
			throw new \Exception("Os campos 'email' e 'senha' são obrigátorios", 400);
		}

		//VERIFICA O USUÁRIO POR EMAIL
		$obUser = User::getUserByEmail($postVars['email']);
		if(!$obUser instanceof User or !password_verify($postVars['senha'], $obUser->senha)){
			throw new \Exception("O usuário ou senha são inválidoss", 400);
		}

		//PAYLOAD
		$payload = [
			'email' => $obUser->email
		];

		//RETORNA O TOKEN GERADO
		return [
			'token' => JWT::encode($payload,getenv('JWT_KEY'),'HS256')
		];
	}
}