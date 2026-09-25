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

$obRouter->post('/painel/assinatura/contrato/aceitar', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		$json = Admin\AssinaturaEscola::aceitarContrato($request);
		$data = json_decode($json, true) ?: [];
		$ok = !empty($data['success']);
		$msg = rawurlencode((string)($data['message'] ?? ''));
		$request->getRouter()->redirect('/painel/assinatura/contrato?aceite='.($ok ? 'ok' : 'erro').'&msg='.$msg);
		return new Response(302, '');
	}
]);

$obRouter->get('/painel/assinatura/contrato', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\AssinaturaEscola::verContrato($request));
	}
]);
