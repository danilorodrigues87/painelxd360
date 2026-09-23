<?php

use App\Http\Response;
use App\Controller\Api\Site;

$siteMw = ['api'];

$respond = static function (array $res) {
	$contentType = $res['contentType'] ?? 'application/json';
	return new Response($res['code'] ?? 200, $res['json'] ?? '{}', $contentType);
};

$obRouter->get('/api/v1/site/public/branding', [
	'middlewares' => $siteMw,
	function ($request) use ($respond) {
		return $respond(Site\PublicApi::branding($request));
	}
]);
