<?php

use App\Http\Response;
use App\Controller\Admin;

$obRouter->get('/painel/estoque', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EstoqueProdutos::index($request));
	},
]);

$obRouter->post('/painel/estoque', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EstoqueProdutos::getInfo($request));
	},
]);

$obRouter->get('/painel/estoque/pdv', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EstoquePdv::index($request));
	},
]);

$obRouter->post('/painel/estoque/pdv', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EstoquePdv::getInfo($request));
	},
]);
