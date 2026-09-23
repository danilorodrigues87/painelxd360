<?php

use App\Http\Response;
use App\Controller\Admin;

$obRouter->get('/painel/whatsapp', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\WhatsappInbox::index($request));
	}
]);

$obRouter->post('/painel/whatsapp', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\WhatsappInbox::getInfo($request), 'application/json');
	}
]);

$obRouter->get('/painel/whatsapp/fluxos', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\WhatsappFluxos::index($request));
	}
]);

$obRouter->post('/painel/whatsapp/fluxos', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\WhatsappFluxos::getInfo($request), 'application/json');
	}
]);
