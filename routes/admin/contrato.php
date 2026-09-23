<?php

use App\Http\Response;
use App\Controller\Admin;

$obRouter->get('/painel/config/contrato', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\ConfigContrato::index($request));
	}
]);

$obRouter->post('/painel/config/contrato', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\ConfigContrato::getInfo($request));
	}
]);
