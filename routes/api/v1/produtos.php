<?php

use App\Http\Response;
use App\Controller\Api\Produtos\Launch;

$respond = static function (array $res) {
	$contentType = $res['contentType'] ?? 'application/json';
	return new Response($res['code'] ?? 200, $res['json'] ?? '{}', $contentType);
};

$obRouter->post('/api/v1/produtos/launch/exchange', [
	'middlewares' => ['api'],
	function ($request) use ($respond) {
		return $respond(Launch::exchange($request));
	}
]);
