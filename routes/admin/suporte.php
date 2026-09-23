<?php

use App\Http\Response;
use App\Controller\Admin;

$obRouter->get('/painel/suporte', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\Suporte::index($request));
	},
]);

$obRouter->post('/painel/suporte', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\Suporte::getInfo($request), 'application/json');
	},
]);

$obRouter->get('/painel/suporte/anexo/{id}', [
	'middlewares' => ['required-admin-login'],
	function ($request, $id) {
		return Admin\Suporte::downloadAnexo($request, $id);
	},
]);
