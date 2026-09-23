<?php

use App\Http\Response;
use App\Controller\Admin;

$obRouter->get('/painel/ead', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EadCursos::index($request));
	}
]);

$obRouter->get('/painel/ead/vitrine', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EadVitrine::index($request));
	}
]);

$obRouter->post('/painel/ead/vitrine', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EadVitrine::getInfo($request));
	}
]);

$obRouter->get('/painel/ead/conquistas', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EadConquistas::index($request));
	}
]);

$obRouter->post('/painel/ead/conquistas', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EadConquistas::getInfo($request));
	}
]);

$obRouter->get('/painel/ead/alunos-online', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EadAlunosOnline::index($request));
	}
]);

$obRouter->post('/painel/ead/alunos-online', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EadAlunosOnline::getInfo($request));
	}
]);

// Alias antigo
$obRouter->get('/painel/ead/online', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		$request->getRouter()->redirect('/painel/ead/alunos-online');
	}
]);

$obRouter->post('/painel/ead/online', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EadAlunosOnline::getInfo($request));
	}
]);

$obRouter->get('/painel/ead/progresso', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EadProgressoTurma::index($request));
	}
]);

$obRouter->post('/painel/ead/progresso', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EadProgressoTurma::getInfo($request));
	}
]);

$obRouter->get('/painel/ead/aluno/{idAluno}', [
	'middlewares' => ['required-admin-login'],
	function ($request, $idAluno) {
		return new Response(200, Admin\EadAlunoProgresso::index($request, $idAluno));
	}
]);

$obRouter->post('/painel/ead/aluno/{idAluno}', [
	'middlewares' => ['required-admin-login'],
	function ($request, $idAluno) {
		return new Response(200, Admin\EadAlunoProgresso::getInfo($request, $idAluno));
	}
]);

$obRouter->get('/painel/ead/curso/{idCurso}', [
	'middlewares' => ['required-admin-login'],
	function ($request, $idCurso) {
		return new Response(200, Admin\EadCursos::editor($request, $idCurso));
	}
]);

// Compat: URL antiga /painel/ead/{idTrilha} → tenta achar curso da trilha
$obRouter->get('/painel/ead/{idTrilha}', [
	'middlewares' => ['required-admin-login'],
	function ($request, $idTrilha) {
		$idAdmin = \App\Common\Helpers\TenantHelper::getIdAdmin();
		$curso = \App\Model\Entity\LmsCurso::getByTrilha((int)$idTrilha, $idAdmin);
		if ($curso) {
			$request->getRouter()->redirect('/painel/ead/curso/'.(int)$curso->id);
			return new Response(302, '');
		}
		$request->getRouter()->redirect('/painel/ead');
		return new Response(302, '');
	}
]);

$obRouter->post('/painel/ead', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\EadCursos::getInfo($request));
	}
]);

$obRouter->get('/painel/config/ia', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\ConfigIa::index($request));
	}
]);

$obRouter->post('/painel/config/ia', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\ConfigIa::getInfo($request));
	}
]);

$obRouter->get('/painel/config/ia/uso', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\RelatorioIa::index($request));
	}
]);

$obRouter->post('/painel/config/ia/uso', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\RelatorioIa::getInfo($request));
	}
]);

$obRouter->get('/painel/config/bunny', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\ConfigBunny::index($request));
	}
]);

$obRouter->post('/painel/config/bunny', [
	'middlewares' => ['required-admin-login'],
	function ($request) {
		return new Response(200, Admin\ConfigBunny::getInfo($request));
	}
]);
