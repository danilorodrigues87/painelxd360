<?php

use App\Http\Response;
use App\Controller\Api\Conect;
use App\Controller\Api\ConectEmpresa;

$conectMw = ['cors-conect', 'api'];
$conectAuth = ['cors-conect', 'api', 'candidato-jwt'];
$empresaAuth = ['cors-conect', 'api', 'empresa-jwt'];
$portalAuth = ['cors-conect', 'api', 'conect-portal-jwt'];

$respond = static function (array $res) {
	$contentType = $res['contentType'] ?? 'application/json';
	return new Response($res['code'] ?? 200, $res['json'] ?? '{}', $contentType);
};

// ——— Público ———
$obRouter->get('/api/v1/conect/public/branding', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\PublicApi::branding($request));
	}
]);

$obRouter->get('/api/v1/conect/public/vagas', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\PublicApi::vagas($request));
	}
]);

$obRouter->get('/api/v1/conect/public/vagas/{slug}', [
	'middlewares' => $conectMw,
	function ($request, $slug) use ($respond) {
		return $respond(Conect\PublicApi::vagaDetalhe($request, (string)$slug));
	}
]);

$obRouter->get('/api/v1/conect/public/empresas', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\PublicApi::empresas($request));
	}
]);

$obRouter->get('/api/v1/conect/public/cidades', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\PublicApi::cidades($request));
	}
]);

$obRouter->get('/api/v1/conect/public/estados', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\PublicApi::estados($request));
	}
]);

$obRouter->get('/api/v1/conect/public/estados/{id}/cidades', [
	'middlewares' => $conectMw,
	function ($request, $id) use ($respond) {
		return $respond(Conect\PublicApi::cidadesPorEstado($request, (int)$id));
	}
]);

$obRouter->post('/api/v1/conect/public/contato', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\PublicApi::contato($request));
	}
]);

$obRouter->get('/api/v1/conect/public/depoimentos', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\PublicApi::depoimentos($request));
	}
]);

$obRouter->post('/api/v1/conect/public/analytics/event', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\Analytics::event($request));
	}
]);

$obRouter->get('/api/v1/conect/public/anuncios', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\Anuncios::listar($request));
	}
]);

$obRouter->get('/api/v1/conect/public/anuncios/config', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\Anuncios::configPublico($request));
	}
]);

$obRouter->post('/api/v1/conect/public/anuncios/{id}/evento', [
	'middlewares' => $conectMw,
	function ($request, $id) use ($respond) {
		return $respond(Conect\Anuncios::evento($request, (int)$id));
	}
]);

$obRouter->get('/api/v1/conect/public/blog/categorias', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\Blog::categorias($request));
	}
]);

$obRouter->get('/api/v1/conect/public/blog/posts', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\Blog::posts($request));
	}
]);

$obRouter->get('/api/v1/conect/public/blog/posts/{slug}/comentarios', [
	'middlewares' => $conectMw,
	function ($request, $slug) use ($respond) {
		return $respond(Conect\Blog::comentarios($request, (string)$slug));
	}
]);

$obRouter->get('/api/v1/conect/public/blog/posts/{slug}', [
	'middlewares' => $conectMw,
	function ($request, $slug) use ($respond) {
		return $respond(Conect\Blog::postDetalhe($request, (string)$slug));
	}
]);

$obRouter->post('/api/v1/conect/blog/posts/{slug}/comentarios', [
	'middlewares' => $portalAuth,
	function ($request, $slug) use ($respond) {
		return $respond(Conect\Blog::criarComentario($request, (string)$slug));
	}
]);

$obRouter->delete('/api/v1/conect/blog/comentarios/{id}', [
	'middlewares' => $portalAuth,
	function ($request, $id) use ($respond) {
		return $respond(Conect\Blog::excluirComentario($request, (int)$id));
	}
]);

// ——— Candidato auth ———
$obRouter->post('/api/v1/conect/auth/login', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\Auth::login($request));
	}
]);

$obRouter->post('/api/v1/conect/auth/register', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\Auth::register($request));
	}
]);

$obRouter->post('/api/v1/conect/auth/forgot-password', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\Auth::forgotPassword($request));
	}
]);

$obRouter->post('/api/v1/conect/auth/reset-password', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(Conect\Auth::resetPassword($request));
	}
]);

$obRouter->get('/api/v1/conect/me', [
	'middlewares' => $conectAuth,
	function ($request) use ($respond) {
		return $respond(Conect\Auth::me($request));
	}
]);

$obRouter->put('/api/v1/conect/me', [
	'middlewares' => $conectAuth,
	function ($request) use ($respond) {
		return $respond(Conect\Perfil::atualizar($request));
	}
]);

$obRouter->post('/api/v1/conect/me/password', [
	'middlewares' => $conectAuth,
	function ($request) use ($respond) {
		return $respond(Conect\Perfil::alterarSenha($request));
	}
]);

$obRouter->post('/api/v1/conect/me/foto', [
	'middlewares' => $conectAuth,
	function ($request) use ($respond) {
		return $respond(Conect\Foto::upload($request));
	}
]);

$obRouter->get('/api/v1/conect/me/foto/arquivo', [
	'middlewares' => $conectAuth,
	function ($request) {
		return Conect\Foto::arquivo($request);
	}
]);

$obRouter->get('/api/v1/conect/candidaturas', [
	'middlewares' => $conectAuth,
	function ($request) use ($respond) {
		return $respond(Conect\Candidaturas::listar($request));
	}
]);

$obRouter->post('/api/v1/conect/candidaturas', [
	'middlewares' => $conectAuth,
	function ($request) use ($respond) {
		return $respond(Conect\Candidaturas::criar($request));
	}
]);

$obRouter->get('/api/v1/conect/notificacoes', [
	'middlewares' => $conectAuth,
	function ($request) use ($respond) {
		return $respond(Conect\Notificacoes::listar($request));
	}
]);

$obRouter->post('/api/v1/conect/notificacoes/{id}/lida', [
	'middlewares' => $conectAuth,
	function ($request, $id) use ($respond) {
		return $respond(Conect\Notificacoes::marcarLida($request, (int)$id));
	}
]);

// ——— Empresa auth ———
$obRouter->post('/api/v1/conect-empresa/auth/login', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Auth::login($request));
	}
]);

$obRouter->post('/api/v1/conect-empresa/auth/register', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Auth::register($request));
	}
]);

$obRouter->post('/api/v1/conect-empresa/auth/forgot-password', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Auth::forgotPassword($request));
	}
]);

$obRouter->post('/api/v1/conect-empresa/auth/reset-password', [
	'middlewares' => $conectMw,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Auth::resetPassword($request));
	}
]);

$obRouter->get('/api/v1/conect-empresa/me', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Auth::me($request));
	}
]);

$obRouter->put('/api/v1/conect-empresa/me', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Perfil::atualizar($request));
	}
]);

$obRouter->post('/api/v1/conect-empresa/me/password', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Perfil::alterarSenha($request));
	}
]);

$obRouter->get('/api/v1/conect-empresa/vagas', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Vagas::listar($request));
	}
]);

$obRouter->post('/api/v1/conect-empresa/vagas', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Vagas::criar($request));
	}
]);

$obRouter->put('/api/v1/conect-empresa/vagas/{id}', [
	'middlewares' => $empresaAuth,
	function ($request, $id) use ($respond) {
		return $respond(ConectEmpresa\Vagas::atualizar($request, (int)$id));
	}
]);

$obRouter->post('/api/v1/conect-empresa/vagas/{id}/acao', [
	'middlewares' => $empresaAuth,
	function ($request, $id) use ($respond) {
		return $respond(ConectEmpresa\Vagas::acao($request, (int)$id));
	}
]);

$obRouter->get('/api/v1/conect-empresa/candidaturas', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Candidaturas::listar($request));
	}
]);

$obRouter->get('/api/v1/conect-empresa/candidaturas/{id}', [
	'middlewares' => $empresaAuth,
	function ($request, $id) use ($respond) {
		return $respond(ConectEmpresa\Candidaturas::detalhe($request, (int)$id));
	}
]);

$obRouter->put('/api/v1/conect-empresa/candidaturas/{id}', [
	'middlewares' => $empresaAuth,
	function ($request, $id) use ($respond) {
		return $respond(ConectEmpresa\Candidaturas::atualizarStatus($request, (int)$id));
	}
]);

$obRouter->post('/api/v1/conect-empresa/logo', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Logo::upload($request));
	}
]);

$obRouter->get('/api/v1/conect-empresa/talentos', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Talentos::buscar($request));
	}
]);

$obRouter->get('/api/v1/conect-empresa/anuncios/config', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Anuncios::config($request));
	}
]);

$obRouter->get('/api/v1/conect-empresa/anuncios/planos', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\AnunciosAssinatura::planos($request));
	}
]);

$obRouter->get('/api/v1/conect-empresa/anuncios/assinatura', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\AnunciosAssinatura::resumo($request));
	}
]);

$obRouter->post('/api/v1/conect-empresa/anuncios/assinatura/cancelar', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\AnunciosAssinatura::cancelar($request));
	}
]);

$obRouter->post('/api/v1/conect-empresa/anuncios/assinatura/verificar', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\AnunciosAssinatura::verificar($request));
	}
]);

$obRouter->post('/api/v1/conect-empresa/anuncios/assinatura', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\AnunciosAssinatura::assinar($request));
	}
]);

$obRouter->get('/api/v1/conect-empresa/anuncios', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Anuncios::listar($request));
	}
]);

$obRouter->post('/api/v1/conect-empresa/anuncios', [
	'middlewares' => $empresaAuth,
	function ($request) use ($respond) {
		return $respond(ConectEmpresa\Anuncios::criar($request));
	}
]);

$obRouter->post('/api/v1/conect-empresa/anuncios/{id}', [
	'middlewares' => $empresaAuth,
	function ($request, $id) use ($respond) {
		return $respond(ConectEmpresa\Anuncios::atualizar($request, (int)$id));
	}
]);

$obRouter->delete('/api/v1/conect-empresa/anuncios/{id}', [
	'middlewares' => $empresaAuth,
	function ($request, $id) use ($respond) {
		return $respond(ConectEmpresa\Anuncios::excluir($request, (int)$id));
	}
]);
