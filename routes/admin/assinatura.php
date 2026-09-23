<?php

use App\Http\Response;
use App\Controller\Admin;

$obRouter->get('/painel/assinatura', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\AssinaturaEscola::index($request));
	}
]);

$obRouter->post('/painel/assinatura', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\AssinaturaEscola::getInfo($request));
	}
]);

$obRouter->get('/painel/assinatura/contrato', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\AssinaturaEscola::verContrato($request));
	}
]);
