<?php

namespace App\Controller\Autentication;

use \App\Utils\View;
use \App\Model\Entity\User;
use \App\Model\Entity\ClientesAssinantes;
use \App\Controller\Admin\Alert;
use \App\Session\User\Login as SessionUserLogin;
use \App\Common\Helpers\MasterGateHelper;
use \App\Common\Helpers\BrandingHelper;

class Login{


	//RETORNA A RENDERIZAÇÃO DA PÁGINA DE LOGIN
	public static function getLogin($request, $errorMessage = null){

		if (!empty($_SESSION['alert'])){
			unset($_SESSION['alert']);
			return self::getLogin($request,'Senha enviada para seu email!');
		}

		//STATUS
		$status = !is_null($errorMessage) ? Alert::getError($errorMessage) : '';

		$logoUrl = BrandingHelper::urlLogoXd360(false);
		$faviconUrl = BrandingHelper::urlFaviconXd360();

		//CONTEUDO DA PAGINA DE LOGIN
		$content = View::render('login/login',[
			'status' => $status,
			'logo_url' => $logoUrl,
		]);

		//RETORNA A PÁGINA COMPLETA
		return View::render('login/page',[
			'title' => 'XD360 — Login',
			'content' => $content,
			'favicon_url' => $faviconUrl,
			'pwa_apple_icon_url' => \App\Common\Helpers\PwaHelper::appleTouchIconUrl(),
		]);
	}

	//DEFINE O LOGIN DO USUÁRIO
	public static function setLogin($request){
		//POST VARS
		$postVars = $request->getPostVars();
		$email = $postVars['email'] ?? '';
		$senha = $postVars['senha'] ?? '';

		$email = filter_var($email, FILTER_SANITIZE_EMAIL);

		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    		return self::getLogin($request, 'Email ou senha inválidos!');
		}

		//BUSCA O USUÁRIO PELO EMAIL
		$obUser = User::getUserByEmail($email);
		if(!$obUser instanceof User){
			return self::getLogin($request,'Email ou senha inválidos!');

		}

		//VERIFICA A SENHA DO USUÁRIO
		if(!password_verify($senha, $obUser->senha)){
			return self::getLogin($request,'Email ou senha inválidos!');
		}

		// verifica se o usuário está ativo antes de logar
		if($obUser->ativo != 's'){
			return self::getLogin($request,'Seu acesso está inativo, contate o suporte.');
		}

		// verifia se o usuário tem permissão para acessar
		if($obUser->nivel == 'Cliente' || $obUser->nivel == 'Empresa' || $obUser->nivel == 'Candidato'){

			// Retorna um alerta de acesso negado
		return self::getLogin($request,'Você não tem permissão para acessar essa área.');

		}

		$isMaster = MasterGateHelper::isMasterEmail($obUser->email ?? '');
		$escola = ClientesAssinantes::getEscolaById((int)$obUser->id_admin);
		$bloqueada = ($escola instanceof ClientesAssinantes && !$escola->isAtiva() && !$isMaster);

		//CRIA A SESSÃO DE LOGIN
		SessionUserLogin::login($obUser);

		if ($isMaster) {
			$_SESSION['usuario-mvc-1']['is_master'] = true;
			$request->getRouter()->redirect('/master');
			return '';
		}

		if ($bloqueada) {
			$_SESSION['usuario-mvc-1']['assinatura_bloqueada'] = true;
			$request->getRouter()->redirect('/painel/assinatura');
			return '';
		}

		$request->getRouter()->redirect('/painel');
		return '';

	}

	//DESLOGA O USUÁRIO
	public static function setLogout($request){
		//DESTROI A SESSÃO DE LOGIN
		SessionUserLogin::logout();

		//REDIRECIONA O USUÁRIO PARA A TELA DE LOGIN
		$request->getRouter()->redirect('/');

	}
}