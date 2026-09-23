<?php

use \App\Http\Response;
use \App\Controller\Admin;

//ROTA DE RESPONSÁVEL
$obRouter->get('/painel/responsavel',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Responsavel::index($request));
	}
]);

//ROTA LISTAGEM DE RESPONSAVEIS
$obRouter->post('/painel/responsavel',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Responsavel::getInfo($request));
	}
]);


//ROTA DE FORMULARIO DE EDIÇÃO
$obRouter->post('/painel/responsavel/form',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Responsavel::getNewUser($request));
	}
]);

//ROTA DE SALVAMENTO DE DADOS
$obRouter->post('/painel/responsavel/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Responsavel::setNewUser($request));
	}
]);


//ROTA DE CONFIRMA EXCLUSAO
$obRouter->post('/painel/responsavel/excluir',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Responsavel::deleteUser($request));
	}
]);