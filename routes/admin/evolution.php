<?php

use \App\Http\Response;
use \App\Controller\Webhook;

$handlerEvolution = static function ($request, $idAdmin, $token) {
	return new Response(200, Webhook\Evolution::receber($request, $idAdmin, $token), 'application/json');
};

// Webhook principal (byEvents=false)
$obRouter->post('/webhook/evolution/{idAdmin}/{token}', [
	'middlewares' => [],
	$handlerEvolution,
]);

// Evolution com webhookByEvents=true envia /messages-upsert etc. no final da URL
$obRouter->post('/webhook/evolution/{idAdmin}/{token}/{evento}', [
	'middlewares' => [],
	$handlerEvolution,
]);

$obRouter->get('/webhook/evolution/{idAdmin}/{token}', [
	'middlewares' => [],
	function ($request, $idAdmin, $token) {
		return new Response(200, json_encode(['ok' => true, 'service' => 'evolution']), 'application/json');
	}
]);
