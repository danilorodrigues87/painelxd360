<?php

use \App\Http\Response;
use \App\Controller\Admin;

$obRouter->get('/painel/campanhas',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\Campanhas::index($request));
	}
]);

$obRouter->post('/painel/campanhas',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\Campanhas::getInfo($request));
	}
]);

// Cron HTTP do worker de campanhas (token = SYSTEM_TOKEN do .env)
$obRouter->get('/cron/campanhas', [
	function ($request) {
		return new Response(200, \App\Controller\Cron\Campanhas::run($request), 'application/json');
	}
]);
$obRouter->post('/cron/campanhas', [
	function ($request) {
		return new Response(200, \App\Controller\Cron\Campanhas::run($request), 'application/json');
	}
]);
