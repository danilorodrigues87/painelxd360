<?php

use \App\Http\Response;
use \App\Controller\Admin;

//ROTA LIOSTAGEM DE USUÁRIOS
$obRouter->get('/painel/user',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\User::index($request));
	}
]);

//ROTA LISTAGEM DE USUÁRIOS
$obRouter->post('/painel/user',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\User::getInfo($request));
	}
]);

//ROTA DE FORMULARIO DE EDIÇÃO
$obRouter->post('/painel/user/form',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\User::getNewUser($request));
	}
]);

//ROTA DE SALVAMENTO DE DADOS
$obRouter->post('/painel/user/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\User::setNewUser($request));
	}
]);

//ROTA DE EXCLUSAO
$obRouter->post('/painel/user/exclusao',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\User::exclusao($request));
	}
]);

//ROTA DE CONFIRMA EXCLUSAO
$obRouter->post('/painel/user/excluir',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\User::deleteUser($request));
	}
]);




/**
 * 
 * ROTAS DINAMICAS
 * DEVEM FICAR POR ULTIMO
 * NUNCA COLOQUE UMA ROTA FIXA DEPOIS DESSAS
 * 
 **/
