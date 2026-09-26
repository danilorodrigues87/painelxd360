<?php

use App\Http\Response;
use App\Controller\Master;

$obRouter->get('/master', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Home::index($request));
	}
]);

$obRouter->get('/master/clientes', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Escolas::index($request));
	}
]);

$obRouter->post('/master/clientes', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Escolas::getInfo($request));
	}
]);

$obRouter->get('/master/planos', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Planos::index($request));
	}
]);

$obRouter->post('/master/planos', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Planos::getInfo($request));
	}
]);

$obRouter->get('/master/assinaturas', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Assinaturas::index($request));
	}
]);

$obRouter->post('/master/assinaturas', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Assinaturas::getInfo($request));
	}
]);

$obRouter->get('/master/contratos', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Contratos::index($request));
	}
]);

$obRouter->post('/master/contratos', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Contratos::getInfo($request), 'application/json');
	}
]);

$obRouter->get('/master/contrato-saas', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\ContratoSaas::index($request));
	}
]);

$obRouter->post('/master/contrato-saas', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\ContratoSaas::getInfo($request), 'application/json');
	}
]);

$obRouter->get('/master/dados-xd360', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\DadosXd360::index($request));
	}
]);

$obRouter->post('/master/dados-xd360', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\DadosXd360::getInfo($request), 'application/json');
	}
]);

$obRouter->get('/master/chamados', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Chamados::index($request));
	}
]);

$obRouter->post('/master/chamados', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Chamados::getInfo($request));
	}
]);

$obRouter->get('/master/documentacao', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Documentacao::index($request));
	}
]);

$obRouter->post('/master/documentacao', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Documentacao::getInfo($request));
	}
]);

$obRouter->get('/master/usuarios', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Usuarios::index($request));
	}
]);

$obRouter->post('/master/usuarios', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Usuarios::getInfo($request));
	}
]);

$obRouter->get('/master/perfil', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Perfil::index($request));
	}
]);

$obRouter->post('/master/perfil/salvar', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Perfil::salvar($request), 'application/json');
	}
]);

$obRouter->post('/master/perfil/senha', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(200, Master\Perfil::alterarSenha($request), 'application/json');
	}
]);

$obRouter->get('/master/voltar', [
	'middlewares' => ['required-master-login'],
	function ($request) {
		return new Response(302, Master\Impersonate::voltar($request), 'text/html', ['Location' => URL.'/master']);
	}
]);
