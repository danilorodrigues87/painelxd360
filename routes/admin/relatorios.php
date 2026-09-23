<?php

use \App\Http\Response;
use \App\Controller\Admin;

//ROTA ENTRADA DE CAIXA
$obRouter->get('/painel/caixa/relatorio',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Relatorios::index($request));
	}
]);

//ROTA LISTAGEM ENTRADA CAIXA
$obRouter->post('/painel/caixa/relatorio',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Relatorios::getInfo($request));
	}
]);
