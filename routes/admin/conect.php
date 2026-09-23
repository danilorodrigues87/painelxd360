<?php

$obRouter->get('/painel/conect', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new \App\Http\Response(200, \App\Controller\Admin\ConectJovem::index($request));
	}
]);

$obRouter->get('/painel/conect/candidatos/novo', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new \App\Http\Response(200, \App\Controller\Admin\ConectJovem::novoCandidato($request));
	}
]);

$obRouter->post('/painel/conect/candidatos/save', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		\App\Controller\Admin\ConectJovem::salvarCandidato($request);
	}
]);
