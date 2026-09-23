<?php

use \App\Http\Response;
use \App\Controller\Admin;

//ROTA DE CLIENTES
$obRouter->get('/painel/clientes',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Clientes::index($request));
	}
]);

//ROTA LISTAGEM DE CLIENTES
$obRouter->post('/painel/clientes',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Clientes::getInfo($request));
	}
]);


//ROTA DE FORMULARIO DE EDIÇÃO
$obRouter->post('/painel/clientes/form',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Clientes::getNewUser($request));
	}
]);

//ROTA DE SALVAMENTO DE DADOS
$obRouter->post('/painel/clientes/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Clientes::setNewUser($request));
	}
]);

//ROTA DE EXCLUSAO
$obRouter->post('/painel/clientes/exclusao',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Clientes::exclusao($request));
	}
]);

//ROTA DE CONFIRMA EXCLUSAO
$obRouter->post('/painel/clientes/excluir',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Clientes::deleteUser($request));
	}
]);

// Anotações / histórico de observações do aluno
$obRouter->post('/painel/clientes/anotacoes',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200, Admin\Clientes::anotacoes($request));
	}
]);

// Extrato financeiro consolidado do aluno
$obRouter->get('/painel/alunos/{idAluno}/extrato', [
	'middlewares' => ['required-admin-login'],
	function ($request, $idAluno) {
		return new Response(200, Admin\AlunoExtrato::index($request, $idAluno));
	}
]);

$obRouter->post('/painel/alunos/{idAluno}/extrato', [
	'middlewares' => ['required-admin-login'],
	function ($request, $idAluno) {
		return new Response(200, Admin\AlunoExtrato::getInfo($request, $idAluno));
	}
]);




/**
 * 
 * ROTAS DINAMICAS
 * DEVEM FICAR POR ULTIMO
 * NUNCA COLOQUE UMA ROTA FIXA DEPOIS DESSAS
 * 
 **/

