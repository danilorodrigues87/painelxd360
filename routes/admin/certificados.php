<?php

use \App\Http\Response;
use \App\Controller\Admin;

//ROTA DE ALUNOS
$obRouter->get('/painel/certificados',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Certificados::index($request));
	}
]);

//ROTA LISTAGEM DE ALUNOS
$obRouter->post('/painel/certificados',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Certificados::getInfo($request));
	}
]);


//ROTA DE FORMULARIO DE EDIÇÃO
$obRouter->post('/painel/certificados/form',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Certificados::getNewCertificado($request));
	}
]);

//ROTA DE SALVAMENTO DE DADOS
$obRouter->post('/painel/certificados/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Certificados::setNewCertificado($request));
	}
]);

//ROTA DE EXCLUSAO
$obRouter->post('/painel/certificados/exclusao',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Certificados::exclusao($request));
	}
]);


//ROTA DE CONFIRMA EXCLUSAO
$obRouter->post('/painel/certificados/excluir',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\Certificados::deleteCertificado($request));
	}
]);




/**
 * 
 * ROTAS DINAMICAS
 * DEVEM FICAR POR ULTIMO
 * NUNCA COLOQUE UMA ROTA FIXA DEPOIS DESSAS
 * 
 **/





$obRouter->get('/painel/certificado/download/{id}',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request,$id){
		return new Response(200,Admin\CertificadoPdf::index($request,$id));
	}
]);