<?php

use \App\Http\Response;
use \App\Controller\Admin;

//ROTA ADMIN
$obRouter->get('/painel',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Home::index($request));
	}
]);

//ROTA ADMIN
$obRouter->post('/painel',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Home::getData($request));
	}
]);




//ROTA TERMOS DE USO
$obRouter->get('/painel/termos-de-uso',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\TermosDeUso::index($request));
	}
]);

//ROTA TERMOS DE USO
$obRouter->post('/painel/aceita-termos',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\TermosDeUso::aceitaTermo($request));
	}
]);

// Central de ajuda (usuário logado)
$obRouter->get('/painel/ajuda',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\Ajuda::index($request));
	}
]);

$obRouter->get('/painel/ajuda/{slug}',[
	'middlewares' => ['required-admin-login'],
	function($request, $slug){
		return new Response(200, Admin\Ajuda::artigo($request, $slug));
	}
]);
