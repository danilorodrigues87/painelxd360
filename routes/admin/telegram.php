<?php

use \App\Http\Response;
use \App\Controller\Webhook;

$obRouter->post('/webhook/telegram/{idAdmin}/{token}', [
	'middlewares' => [],
	function ($request, $idAdmin, $token) {
		return new Response(200, Webhook\Telegram::receber($request, $idAdmin, $token), 'application/json');
	}
]);

$obRouter->get('/webhook/telegram/{idAdmin}/{token}', [
	'middlewares' => [],
	function ($request, $idAdmin, $token) {
		return new Response(200, Webhook\Telegram::ping($idAdmin, $token), 'application/json');
	}
]);
