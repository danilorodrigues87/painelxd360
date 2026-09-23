<?php

use \App\Http\Response;
use \App\Controller\Admin;

// Rota legada: controller Admin\Caixa nunca existiu — redireciona para Entrada
$obRouter->get('/painel/caixa',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		$request->getRouter()->redirect('/painel/caixa/entrada');
	}
]);


// ENTRADA DO CAIXA

//ROTA ENTRADA DE CAIXA
$obRouter->get('/painel/caixa/entrada',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaEntrada::index($request));
	}
]);

//ROTA LISTAGEM ENTRADA CAIXA
$obRouter->post('/painel/caixa/entrada',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaEntrada::getInfo($request));
	}
]);

//ROTA LISTAGEM ENTRADA CAIXA
$obRouter->post('/painel/caixa/entrada/form',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaEntrada::getNewCaixa($request));
	}
]);

//ROTA ENTRADA CAIXA - REGISTRA MOVIMENTO
$obRouter->post('/painel/caixa/entrada/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaEntrada::registrarPagamento($request));
	}
]);


// SAIDA DE CAIXA

//ROTA ENTRADA DE CAIXA
$obRouter->get('/painel/caixa/saida',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaSaida::index($request));
	}
]);

//ROTA LISTAGEM ENTRADA CAIXA
$obRouter->post('/painel/caixa/saida',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaSaida::getInfo($request));
	}
]);

//ROTA LISTAGEM ENTRADA CAIXA
$obRouter->post('/painel/caixa/saida/form',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaSaida::getNewCaixa($request));
	}
]);

//ROTA ENTRADA CAIXA - REGISTRA MOVIMENTO
$obRouter->post('/painel/caixa/saida/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaSaida::registrarPagamento($request));
	}
]);
 

// CARRINHO DO CAIXA

//RESUMO DO CARRINHO
$obRouter->post('/painel/caixa/carrinho/get',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaCarrinho::getResumo($request));
	}
]);

//ADICIONAR TÍTULO (LANÇAMENTO DO CAIXA) AO CARRINHO
$obRouter->post('/painel/caixa/carrinho/add-titulo',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaCarrinho::addTitulo($request));
	}
]);

//ADICIONAR ITEM AVULSO (SERVIÇO/PRODUTO) AO CARRINHO
$obRouter->post('/painel/caixa/carrinho/add-avulso',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaCarrinho::addAvulso($request));
	}
]);

//REMOVER ITEM DO CARRINHO
$obRouter->post('/painel/caixa/carrinho/remove',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaCarrinho::removerItem($request));
	}
]);

//FORMULÁRIO DE PAGAMENTO DO CARRINHO
$obRouter->post('/painel/caixa/carrinho/form',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaCarrinho::formPagamento($request));
	}
]);

//FINALIZA O PAGAMENTO DO CARRINHO
$obRouter->post('/painel/caixa/carrinho/finalizar',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CaixaCarrinho::finalizar($request));
	}
]);