<?php

use \App\Http\Response;
use \App\Controller\Api;

//ROTA RECEBIMENTO DE MENSAGEM VIA API
$obRouter->get('/api/v1/trilhas',[
	'middlewares' => [
		'api',
		'user-basic-auth',
		'cache'
	],
	function($request){
		return new Response(200,Api\Trilhas::getTrilha($request),'application/json');
	}
]);


//ROTA DE CADASTRO DE DADOS VIA API
$obRouter->post('/api/v1/nova-trilha',[
	'middlewares' => [
		'api',
		'user-basic-auth'
	],
	function($request){
		return new Response(201,Api\Trilhas::setNewTrilha($request),'application/json');
	}
]);


/**
 * 
 * ROTAS DINAMICAS
 * DEVEM FICAR POR ULTIMO
 * NUNCA COLOQUE UMA ROTA FIXA DEPOIS DESSAS
 * 
 **/



//ROTA RECEBIMENTO DE DOADS VIA API COM BASE NO ID
$obRouter->get('/api/v1/trilhas/{id}',[
	'middlewares' => [
		'api',
		'user-basic-auth',
		'cache'
	],
	function($request,$id){
		return new Response(200,Api\Trilhas::getTrilhaById1($request,$id),'application/json');
	}
]);

//ROTA DE ATUALIZAÇÃO DE TRILHA VIA API
$obRouter->put('/api/v1/edita-trilha/{id}',[
	'middlewares' => [
		'api',
		'user-basic-auth'
	],
	function($request,$id){
		return new Response(200,Api\Trilhas::setEditTrilha($request,$id),'application/json');
	}
]);


//ROTA DE EXCLUSÃO DE TRILHA VIA API
$obRouter->delete('/api/v1/delete-trilha/{id}',[
	'middlewares' => [
		'api',
		'user-basic-auth'
	],
	function($request,$id){
		return new Response(200,Api\Trilhas::setDeleteTrilha($request,$id),'application/json');
	}
]);