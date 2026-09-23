<?php 

namespace App\Controller\Admin;
use \App\Utils\View;

class Alert{

	//RETORNA UMA MENSAGEM DE ERRO
	public static function getError($message){
		return View::render('alert/status',[
			'tipo' => 'danger',
			'mensagem' => $message
		]);

	}

	//RETORNA UMA MENSAGEM DE SUCESSO
	public static function getSuccess($message){
		return View::render('alert/status',[
			'tipo' => 'success',
			'mensagem' => $message
		]);

	}
}