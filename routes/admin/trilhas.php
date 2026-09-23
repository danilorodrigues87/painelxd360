<?php

use \App\Http\Response;
use \App\Controller\Admin;

//ROTA DE ALUNOS
$obRouter->get('/painel/trilhas',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Trilhas::index($request));
	}
]);

//ROTA LISTAGEM DE ALUNOS
$obRouter->post('/painel/trilhas',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Trilhas::getInfo($request));
	}
]);


//ROTA DE FORMULARIO DE EDIÇÃO
$obRouter->post('/painel/trilhas/form',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Trilhas::getNewTrilha($request));
	}
]);

//ROTA DE SALVAMENTO DE DADOS
$obRouter->post('/painel/trilhas/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Trilhas::setNewTrilha($request));
	}
]);

//ROTA DE EXCLUSAO
$obRouter->post('/painel/trilhas/exclusao',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Trilhas::exclusao($request));
	}
]);

//ROTA DE CONFIRMA EXCLUSAO
$obRouter->post('/painel/trilhas/excluir',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Trilhas::deleteTrilha($request));
	}
]);



/**
 * 
 * ROTAS DINAMICAS
 * DEVEM FICAR POR ULTIMO
 * NUNCA COLOQUE UMA ROTA FIXA DEPOIS DESSAS
 * 
 **/
