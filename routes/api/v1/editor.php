<?php

use App\Http\Response;
use App\Controller\Api\Editor;

$editorMw = ['api', 'cors-student', 'editor-jwt'];
$editorPublic = ['api', 'cors-student'];

$respond = static function (array $res) {
	$contentType = $res['contentType'] ?? 'application/json';
	return new Response($res['code'] ?? 200, $res['json'] ?? '{}', $contentType);
};

// Exchange (sem editor-jwt)
$obRouter->post('/api/v1/editor/auth/exchange', [
	'middlewares' => $editorPublic,
	function ($request) use ($respond) {
		return $respond(Editor\Auth::exchange($request));
	}
]);

$obRouter->get('/api/v1/editor/auth/exchange', [
	'middlewares' => $editorPublic,
	function ($request) use ($respond) {
		return $respond(Editor\Auth::exchange($request));
	}
]);

$obRouter->post('/api/v1/editor/exchange', [
	'middlewares' => $editorPublic,
	function ($request) use ($respond) {
		return $respond(Editor\Auth::exchange($request));
	}
]);

$obRouter->get('/api/v1/editor/exchange', [
	'middlewares' => $editorPublic,
	function ($request) use ($respond) {
		return $respond(Editor\Auth::exchange($request));
	}
]);

$obRouter->get('/api/v1/editor/aulas', [
	'middlewares' => $editorMw,
	function ($request) use ($respond) {
		return $respond(Editor\Aulas::listar($request));
	}
]);

$obRouter->post('/api/v1/editor/aulas', [
	'middlewares' => $editorMw,
	function ($request) use ($respond) {
		return $respond(Editor\Aulas::criar($request));
	}
]);

$obRouter->get('/api/v1/editor/aulas/{id}', [
	'middlewares' => $editorMw,
	function ($request, $id) use ($respond) {
		return $respond(Editor\Aulas::getAula($request, $id));
	}
]);

$obRouter->put('/api/v1/editor/aulas/{id}', [
	'middlewares' => $editorMw,
	function ($request, $id) use ($respond) {
		return $respond(Editor\Aulas::salvarAula($request, $id));
	}
]);

$obRouter->post('/api/v1/editor/upload', [
	'middlewares' => $editorMw,
	function ($request) use ($respond) {
		return $respond(Editor\Aulas::upload($request));
	}
]);

$obRouter->post('/api/v1/editor/tts', [
	'middlewares' => $editorMw,
	function ($request) use ($respond) {
		return $respond(Editor\Tts::gerar($request));
	}
]);
