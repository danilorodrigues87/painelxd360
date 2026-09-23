<?php

use App\Http\Response;
use App\Controller\Admin;

$obRouter->get('/painel/perfil', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\Perfil::index($request));
	}
]);

$obRouter->post('/painel/perfil/salvar', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\Perfil::salvar($request));
	}
]);
