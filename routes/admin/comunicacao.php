<?php

use \App\Http\Response;
use \App\Controller\Admin;

$obRouter->get('/painel/config/comunicacao',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200, Admin\ConfigComunicacao::index($request));
	}
]);

$obRouter->post('/painel/config/comunicacao',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200, Admin\ConfigComunicacao::getInfo($request));
	}
]);

// Cron HTTP — cobrança e aniversário (token = SYSTEM_TOKEN do .env)
$obRouter->get('/cron/cobranca', [
	function ($request) {
		return new Response(200, \App\Controller\Cron\Cobranca::run($request), 'application/json');
	}
]);
$obRouter->post('/cron/cobranca', [
	function ($request) {
		return new Response(200, \App\Controller\Cron\Cobranca::run($request), 'application/json');
	}
]);
$obRouter->get('/cron/aniversario', [
	function ($request) {
		return new Response(200, \App\Controller\Cron\Aniversario::run($request), 'application/json');
	}
]);
$obRouter->post('/cron/aniversario', [
	function ($request) {
		return new Response(200, \App\Controller\Cron\Aniversario::run($request), 'application/json');
	}
]);
