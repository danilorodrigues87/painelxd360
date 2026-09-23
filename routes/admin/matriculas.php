<?php

use \App\Http\Response;
use \App\Controller\Admin;

//ROTA DE ALUNOS
$obRouter->get('/painel/matriculas',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Matriculas::index($request));
	}
]);

//ROTA LISTAGEM DE ALUNOS
$obRouter->post('/painel/matriculas',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Matriculas::getInfo($request));
	}
]);


//ROTA DE FORMULARIO DE EDIÇÃO
$obRouter->post('/painel/matriculas/form',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Matriculas::getNovaMatricula($request));
	}
]);

//ROTA DE SALVAMENTO DE DADOS
$obRouter->post('/painel/matriculas/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Matriculas::setNovaMatricula($request));
	}
]);

//ROTA DE CANCELAMENTO
$obRouter->post('/painel/matriculas/cancelar/simular',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Matriculas::simularCancelamento($request));
	}
]);

$obRouter->post('/painel/matriculas/cancelar',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Matriculas::cancelarMatricula($request));
	}
]);

$obRouter->post('/painel/matriculas/regularizar/simular',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Matriculas::simularRegularizacaoFinanceira($request));
	}
]);

$obRouter->post('/painel/matriculas/regularizar',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Matriculas::regularizarFinanceiro($request));
	}
]);

//ROTA DE ENCERRAMENTO
$obRouter->post('/painel/matriculas/encerrar',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Matriculas::encerrarMatricula($request));
	}
]);


//ROTA DE FORMULARIO DE EDIÇÃO
$obRouter->post('/painel/busca-responsavel',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Matriculas::getResponseble($request));
	}
]);



/**
 * 
 * ROTAS DINAMICAS
 * DEVEM FICAR POR ULTIMO
 * NUNCA COLOQUE UMA ROTA FIXA DEPOIS DESSAS
 * 
 **/



//ROTA VER CONTRATO
$obRouter->get('/painel/matricula/{id}',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request,$id){
		return new Response(200,Admin\Matriculas::verContrato($request,$id));
	}
]);