<?php

use App\Http\Response;
use App\Controller\Admin;
use App\Controller\Webhook;

$obRouter->get('/painel/social/mensagens', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\MetaInbox::index($request));
	}
]);

$obRouter->post('/painel/social/mensagens', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\MetaInbox::getInfo($request));
	}
]);

$obRouter->get('/painel/social', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\SocialAgenda::index($request));
	}
]);

$obRouter->post('/painel/social', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\SocialAgenda::getInfo($request));
	}
]);

$obRouter->post('/painel/social/upload', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\SocialAgenda::upload($request));
	}
]);

$obRouter->get('/painel/config/social', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\ConfigSocial::index($request));
	}
]);

$obRouter->post('/painel/config/social', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\ConfigSocial::getInfo($request));
	}
]);

$obRouter->get('/painel/config/social/oauth/callback', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\ConfigSocial::oauthCallback($request));
	}
]);

// Webhook Meta (sem login)
$obRouter->get('/webhook/meta', [
	function ($request) {
		return new Response(200, Webhook\Meta::verifyGlobal($request));
	}
]);

$obRouter->post('/webhook/meta', [
	function ($request) {
		return new Response(200, Webhook\Meta::receberGlobal($request), 'application/json');
	}
]);

$obRouter->get('/webhook/meta/{idAdmin}/{token}', [
	function ($request, $idAdmin, $token) {
		return new Response(200, Webhook\Meta::receber($request, $idAdmin, $token));
	}
]);

$obRouter->post('/webhook/meta/{idAdmin}/{token}', [
	function ($request, $idAdmin, $token) {
		return new Response(200, Webhook\Meta::receber($request, $idAdmin, $token), 'application/json');
	}
]);

// Cron HTTP do worker social (token = SYSTEM_TOKEN do .env)
$obRouter->get('/cron/social', [
	function ($request) {
		return new Response(200, \App\Controller\Cron\Social::run($request), 'application/json');
	}
]);
$obRouter->post('/cron/social', [
	function ($request) {
		return new Response(200, \App\Controller\Cron\Social::run($request), 'application/json');
	}
]);
