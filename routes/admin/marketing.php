<?php

use App\Http\Response;
use App\Controller\Admin;

$obRouter->get('/painel/marketing/aniversariantes', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\MarketingAniversariantes::index($request));
	}
]);

$obRouter->post('/painel/marketing/aniversariantes', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\MarketingAniversariantes::getInfo($request), 'application/json');
	}
]);

$obRouter->get('/painel/marketing/biblioteca', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\MarketingBiblioteca::index($request));
	}
]);

$obRouter->post('/painel/marketing/biblioteca', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\MarketingBiblioteca::getInfo($request), 'application/json');
	}
]);

$obRouter->post('/painel/marketing/biblioteca/upload', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\MarketingBiblioteca::upload($request), 'application/json');
	}
]);
