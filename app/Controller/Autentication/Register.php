<?php

namespace App\Controller\Autentication;

use \App\Utils\View;
use \App\Model\Entity\User;
use \App\Controller\Admin\Alert;
use \App\Session\User\Login as SessionUserLogin;
use \App\Common\Helpers\EmailValidator;
use \App\Common\Helpers\BrandingHelper;

class Register{

	//RETORNA A RENDERIZAÇÃO DA PÁGINA DE LOGIN
	public static function getRegister($request, $errorMessage = null){

		//STATUS
		$status = !is_null($errorMessage) ? Alert::getError($errorMessage) : '';

		//CONTEUDO DA PAGINA DE CADASTRO
		$content = View::render('login/register',[
			'status' => $status,

		]);

		//RETORNA A PÁGINA COMPLETA
		return View::render('login/page',[
			'title' => 'Criar conta',
			'content' => $content,
			'favicon_url' => BrandingHelper::urlFaviconCti(),
		]);
	}

	//DEFINE O LOGIN DO USUÁRIO
	public static function setRegister($request){

		//POST VARS
		$postVars = $request->getPostVars();

		$nome = $postVars['nome'] ?? '';
		$email = EmailValidator::normalizar($postVars['email'] ?? '');
		$senha1 = $postVars['senha1'] ?? '';
		$senha2 = $postVars['senha2'] ?? '';

		$erroEmail = EmailValidator::mensagemErro($email, true);
		if ($erroEmail !== null) {
			return self::getRegister($request, $erroEmail);
		}

		//BUSCA O USUÁRIO PELO EMAIL
		$obUser = User::getUserByEmail($email);

		if($obUser instanceof User){
			return self::getRegister($request,'Esse email já está em uso.');
		}

		if($senha1 != $senha2){
			return self::getRegister($request,'As senhas não coincidem.');
		}

		//NOVA INSTANCIA
		$obUsers = new User;
		$obUsers->nome = $nome;
		$obUsers->email = $email;
		$obUsers->nivel = 'Aluno';
		$obUsers->senha = password_hash($senha1, PASSWORD_DEFAULT);
		$obUsers->cadastrar();

		//CRIA A SESSÃO DE LOGIN
		SessionUserLogin::login($obUser);

		//REDIRECIONA O USUÁRIO PARA A HOME DO ADMIN
		$request->getRouter()->redirect('/painel');
	}

}