<?php

use \App\Http\Response;
use \App\Common;
use \App\Controller\Admin;



//ROTA ESTADO E CIDADES
$obRouter->post('/painel/get_cidades',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Common\Functions::getCidades($request));
	}
]);



// Notificações in-app (sino navbar)
$obRouter->post('/painel/notificacoes', [
	'middlewares' => [
		'required-admin-login',
	],
	function ($request) {
		return new Response(200, Admin\StaffNotificacoes::getInfo($request), 'application/json');
	},
]);



//ROTA EDIÇÃO DE PERFIL DO USUÁRIO
$obRouter->get('/painel/form-profile',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Common\Functions::formProfile());
	}
]);


//ROTA EDIÇÃO DE PERFIL DO USUÁRIO
$obRouter->post('/painel/save-profile',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Common\Functions::saveProfile($request));
	}
]);




/**
 * 
 * ROTAS DINAMICAS
 * DEVEM FICAR POR ULTIMO
 * NUNCA COLOQUE UMA ROTA FIXA DEPOIS DESSAS
 * 
 **/

