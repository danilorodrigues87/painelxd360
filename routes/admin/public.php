<?php

use App\Http\Response;
use App\Controller\PublicPages;

// Política de privacidade — pública (Meta App Review / Facebook)
$obRouter->get('/privacidade', [
	function ($request) {
		return new Response(200, PublicPages\Privacy::index($request));
	}
]);

$obRouter->get('/privacy', [
	function ($request) {
		return new Response(200, PublicPages\Privacy::index($request));
	}
]);

$obRouter->get('/privacy-policy', [
	function ($request) {
		return new Response(200, PublicPages\Privacy::index($request));
	}
]);

// Exclusão de dados do usuário (Meta User Data Deletion)
$obRouter->get('/exclusao-de-dados', [
	function ($request) {
		return new Response(200, PublicPages\DataDeletion::index($request));
	}
]);

$obRouter->post('/exclusao-de-dados', [
	function ($request) {
		return new Response(200, PublicPages\DataDeletion::index($request));
	}
]);

$obRouter->get('/data-deletion', [
	function ($request) {
		return new Response(200, PublicPages\DataDeletion::index($request));
	}
]);

$obRouter->post('/data-deletion', [
	function ($request) {
		return new Response(200, PublicPages\DataDeletion::index($request));
	}
]);

$obRouter->get('/user-data-deletion', [
	function ($request) {
		return new Response(200, PublicPages\DataDeletion::index($request));
	}
]);

// Central de ajuda (pública)
$obRouter->get('/ajuda', [
	function ($request) {
		return new Response(200, \App\Controller\Admin\Ajuda::indexPublico($request));
	}
]);

$obRouter->get('/ajuda/{slug}', [
	function ($request, $slug) {
		return new Response(200, \App\Controller\Admin\Ajuda::artigoPublico($request, $slug));
	}
]);

// PWA — manifest dinâmico + service worker (fallback se .htaccess não servir sw.js)
$obRouter->get('/manifest.webmanifest', [
	function ($request) {
		return new Response(200, PublicPages\Pwa::manifest($request), 'application/manifest+json');
	}
]);

$obRouter->get('/sw.js', [
	function ($request) {
		$response = new Response(200, PublicPages\Pwa::serviceWorker($request), 'application/javascript');
		$response->addHeader('Service-Worker-Allowed', \App\Common\Helpers\PwaHelper::serviceWorkerAllowedHeader());
		$response->addHeader('Cache-Control', 'no-cache');
		return $response;
	}
]);

$obRouter->get('/OneSignalSDKWorker.js', [
	function ($request) {
		$response = new Response(200, PublicPages\Pwa::oneSignalWorker($request), 'application/javascript');
		$response->addHeader('Cache-Control', 'no-cache');
		return $response;
	}
]);

$obRouter->get('/OneSignalSDKUpdaterWorker.js', [
	function ($request) {
		$response = new Response(200, PublicPages\Pwa::oneSignalUpdaterWorker($request), 'application/javascript');
		$response->addHeader('Cache-Control', 'no-cache');
		return $response;
	}
]);

$obRouter->get('/push/onesignal/OneSignalSDKWorker.js', [
	function ($request) {
		$response = new Response(200, PublicPages\Pwa::oneSignalWorker($request), 'application/javascript');
		$response->addHeader('Cache-Control', 'no-cache');
		return $response;
	}
]);

$obRouter->get('/push/onesignal/OneSignalSDKUpdaterWorker.js', [
	function ($request) {
		$response = new Response(200, PublicPages\Pwa::oneSignalUpdaterWorker($request), 'application/javascript');
		$response->addHeader('Cache-Control', 'no-cache');
		return $response;
	}
]);
