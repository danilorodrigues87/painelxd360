<?php

use App\Http\Response;
use App\Controller\Admin;

$obRouter->get('/painel/produtos', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\Produtos::index($request));
	}
]);

$obRouter->get('/painel/produtos/abrir/{slug}', [
	'middlewares' => ['required-admin-login'],
	function ($request, $slug) {
		return Admin\ProdutosLaunch::abrir($request, (string)$slug);
	}
]);
