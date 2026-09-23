<?php
use \App\Http\Response;
use \App\Controller\Api;

//ROTA RECEBIMENTO DE MENSAGEM VIA API
$obRouter->get('/api/v1',[
	'middlewares' => [
		'api',
		'user-basic-auth'
	],
	function($request){
		return new Response(200,Api\Api::getDetails($request),'application/json');
	}
]);

