<?php

use \App\Http\Response;
use \App\Controller\Admin;

$obRouter->get('/painel/financeiro/inadimplentes', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\Inadimplentes::index($request));
	},
]);

$obRouter->post('/painel/financeiro/inadimplentes', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		$out = Admin\Inadimplentes::getInfo($request);
		if ($out instanceof Response) {
			return $out;
		}
		return new Response(200, $out, 'application/json');
	},
]);
