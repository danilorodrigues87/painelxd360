<?php

namespace App\Controller\Autentication;

use \App\Utils\View;
use \App\Controller\Admin\Alert;
use \App\Model\Entity\User; 
use \App\Common\Communication\Email;
use \App\Common\Helpers\BrandingHelper;

class recCode{

	//RETORNA A RENDERIZAÇÃO DA PÁGINA DE CÓDIGO
	public static function index($request, $errorMessage = null){

		//STATUS
		$status = !is_null($errorMessage) ? Alert::getError($errorMessage) : '';

		//CONTEUDO DA PAGINA DE CADASTRO
		$content = View::render('login/recCode',[
			'status' => $status,

		]);

		//RETORNA A PÁGINA COMPLETA
		return View::render('login/page',[
			'title' => 'Código de Segurança',
			'content' => $content,
			'favicon_url' => BrandingHelper::urlFaviconCti(),
		]);
	}

	public static function generatePassword(int $length = 8): string
	{
		return substr(bin2hex(random_bytes($length)), 0, $length);
	}


	public static function checkCode($request){

		$postVars = $request->getPostVars();
		$codigo = $postVars['codigo'] ?? '';

		if (!preg_match('/^\d{6}$/', $codigo)) {
			return self::index($request,'Código inválido.');
		}

		$obUser = User::getUserByCode($codigo);
		if(!$obUser instanceof User){
			return self::index($request,'Código inválido');
		}

		$email = $obUser->email;

		// GERA NOVA SENHA
		$novaSenha = self::generatePassword(10);

		$address = $email;
		$subject = 'Sua Nova Senha';
		$body = '<p>Sua nova senha de acesso é:<p><br><b>'.$novaSenha.'</b>';

		$obEmail = Email::sistema();
		$res = $obEmail->sendEmail($address,$subject,$body);
		$res = $res ? true : $obEmail->getError();
		if(!$res){
			return self::index($request,$res);
		}
		
		// Nova instância e reset da senha
		$obUsers = new User;
		$obUsers->id = $obUser->id;
		$obUsers->senha = password_hash($novaSenha, PASSWORD_DEFAULT);
		$resetResult = $obUsers->resetSenha();

		$_SESSION['alert'] = true;

		//DIRECIONA PARA A PAGINA DE CÓDIGO
		$request->getRouter()->redirect('/');



	}



}