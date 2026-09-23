<?php

namespace App\Controller\Autentication;

use \App\Utils\View;
use \App\Controller\Admin\Alert;
use \App\Session\User\Login as SessionUser;
use \App\Model\Entity\User;
use \App\Common\Helpers\TenantHelper; 
use \App\Common\Communication\Email;
use \App\Common\Helpers\BrandingHelper;

class Recovery{

	//RETORNA A RENDERIZAÇÃO DA PÁGINA DE LOGIN
	public static function index($request, $errorMessage = null){

		//STATUS
		$status = !is_null($errorMessage) ? Alert::getError($errorMessage) : '';

		//CONTEUDO DA PAGINA DE CADASTRO
		$content = View::render('login/recovery',[
			'status' => $status,

		]);

		//RETORNA A PÁGINA COMPLETA
		return View::render('login/page',[
			'title' => 'Recuperar senha',
			'content' => $content,
			'favicon_url' => BrandingHelper::urlFaviconCti(),
		]);
	}

	public static function generateCode(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}


	public static function sendCode($request){

		$postVars = $request->getPostVars();

		if (!isset($postVars['email']) || empty($postVars['email'])) {
        return self::index($request, 'E-mail é obrigatório');
    }	
    $email = filter_var($postVars['email'], FILTER_SANITIZE_EMAIL);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return self::index($request, 'E-mail inválido');
    }
    if (preg_match('/\r|\n/', $email)) {
        return self::index($request, 'E-mail inválido');
    }

    //BUSCA O USUÁRIO PELO EMAIL
		$obUser = User::getUserByEmail($email);
		if(!$obUser instanceof User){
			return self::index($request,'Email inválidos');
		}

    	$code = self::generateCode();

    	// NOVA INSTANCIA E ENVIA O CÓDIGO
		$obUserRec = new User;
		$obUserRec->code = $code;
		$obUserRec->id = $obUser->id;

		$resRecCode = $obUserRec->setRecCode();

		// Verificação do resultado do envio
		if (!$resRecCode) {
			return self::index($request,'Erro ao enviar código.');
		}

		$address = $email;
		$subject = 'Recuperação de senha';
		$body = '<p>Seu código de recuepração de senha é:<p><br><b>'.$code.'</b>';

		$obEmail = Email::sistema();
		$res = $obEmail->sendEmail($address,$subject,$body);
		$res = $res ? true : $obEmail->getError();
		if(!$res){
			return self::index($request,$res);

		}
		
		//DIRECIONA PARA A PAGINA DE CÓDIGO
		$request->getRouter()->redirect('/codigo-de-seguranca');

	}

	public static function setNewPass($request) {

		$postVars = $request->getPostVars();

		$userLogedData = SessionUser::getUserLogedData();

    // Definir senha padrão
		$novaSenha = '12345678';


		if(isset($postVars['id']) AND $postVars['id'] !=''){

			$id_user = (int)$postVars['id'];
			$id_admin = (int)$userLogedData['usuario']['id_admin'];

			if(!TenantHelper::pertenceUsuario($id_user, $id_admin)){
				return 'Usuário não encontrado.';
			}

		} else {

    	// Verificação das senhas
			if (isset($postVars['senha1']) && isset($postVars['senha2'])) {
				if ($postVars['senha1'] == null) { 
					return 'Senha inválida'; 
				}
				if ($postVars['senha2'] == null) { 
					return 'Senha inválida'; 
				}
				if ($postVars['senha1'] !== $postVars['senha2']) {
					return 'As senhas não coincidem';
				}

        		// Nova senha
				$novaSenha = $postVars['senha1'];
				if(strlen($novaSenha) < 8){
					return 'A senha deve ter pelo menos 8 caracteres.';
				}

			}

			$id_user = $userLogedData['usuario']['id'];
		}


    // Nova instância e reset da senha
		$obUsers = new User;
		$obUsers->id = $id_user;
		$obUsers->senha = password_hash($novaSenha, PASSWORD_DEFAULT);
		$resetResult = $obUsers->resetSenha();

    // Verificação do resultado do reset
		if ($resetResult) {
			return true;
		} else {
			return 'Erro ao resetar senha';
		}
		
	}


}