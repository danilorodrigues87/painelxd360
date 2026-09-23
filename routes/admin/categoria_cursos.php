<?php

use \App\Http\Response;
use \App\Controller\Admin;

//ROTA DE ALUNOS
$obRouter->get('/painel/categoria/cursos',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CategoryCourses::index($request));
	}
]);

//ROTA LISTAGEM DE ALUNOS
$obRouter->post('/painel/categoria/cursos',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CategoryCourses::getInfo($request));
	}
]);


//ROTA DE FORMULARIO DE EDIÇÃO
$obRouter->post('/painel/categoria/cursos/form',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CategoryCourses::getNewCategory($request));
	}
]);

//ROTA DE SALVAMENTO DE DADOS
$obRouter->post('/painel/categoria/cursos/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CategoryCourses::setNewCategory($request));
	}
]);

//ROTA DE EXCLUSAO
$obRouter->post('/painel/categoria/cursos/exclusao',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CategoryCourses::exclusao($request));
	}
]);

//ROTA DE CONFIRMA EXCLUSAO
$obRouter->post('/painel/categoria/cursos/excluir',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CategoryCourses::deleteCategory($request));
	}
]);

$obRouter->get('/painel/categoria/cursos/contrato/{id}',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200, Admin\CategoryCourses::contratoIndex($request));
	}
]);

$obRouter->post('/painel/categoria/cursos/contrato/{id}',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200, Admin\CategoryCourses::contratoApi($request));
	}
]);