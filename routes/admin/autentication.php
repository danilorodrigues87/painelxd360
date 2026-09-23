<?php

use \App\Http\Response;
use \App\Controller\Autentication;

//ROTA LOGIN
$obRouter->get('/',[
	'middlewares' => [
		'required-admin-logout'
	],
	function($request){
		return new Response(200,Autentication\Login::getLogin($request));
	}
]);

//ROTA LOGIN POST
$obRouter->post('/',[
	'middlewares' => [
		'required-admin-logout'
	],
	function($request){
		return new Response(200,Autentication\Login::setLogin($request));
	}
]);

//ROTA LOGOUT
$obRouter->get('/logout',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Autentication\Login::setLogout($request));
	}
]);


//ROTA TELA DE RECUPERAÇÃO DE SENHA
$obRouter->get('/rercurar-senha',[
	'middlewares' => [
		'required-admin-logout'
	],
	function($request){
		return new Response(200,Autentication\Recovery::index($request));
	}
]);
//ROTA TELA DE RECUPERAÇÃO DE SENHA
$obRouter->post('/rercurar-senha',[
	'middlewares' => [
		'required-admin-logout'
	],
	function($request){
		return new Response(200,Autentication\Recovery::sendCode($request));
	}
]);

//ROTA TELA DE RECUPERAÇÃO DE SENHA
$obRouter->get('/codigo-de-seguranca',[
	'middlewares' => [
		'required-admin-logout'
	],
	function($request){
		return new Response(200,Autentication\recCode::index($request));
	}
]);


//ROTA TELA DE RECUPERAÇÃO DE SENHA
$obRouter->post('/codigo-de-seguranca',[
	'middlewares' => [
		'required-admin-logout'
	],
	function($request){
		return new Response(200,Autentication\recCode::checkCode($request));
	}
]);


//ROTA RESET DE SENHA DO USUÁRIO
$obRouter->post('/reset_senha',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Autentication\Recovery::setNewPass($request));
	}
]);



/* ROTA DE CADASTRO POR ENQUANTO FICARÁ DESATIVADA

//ROTA CADASTRO
$obRouter->get('/cadastro',[
	'middlewares' => [
		'required-admin-logout'
	],
	function($request){
		return new Response(200,Autentication\Register::getRegister($request));
	}
]);

//ROTA CADASTRO POST
$obRouter->post('/cadastro',[
	'middlewares' => [
		'required-admin-logout'
	],
	function($request){
		return new Response(200,Autentication\Register::setRegister($request));
	}
]);

*/