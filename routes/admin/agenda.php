<?php

use \App\Http\Response;
use \App\Controller\Admin;

// AGENDA — PLANO SEMANAL (agendar alunos)
$obRouter->get('/painel/agenda/laboratorio',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::index($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorio',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::getInfo($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorio/form',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::verDados($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorio/editar',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::editar($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorio/excluir',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::excluir($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorio/horarios',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::listarHorarios($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorio/aluno',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::infoAluno($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorio/salvar',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::salvar($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorio/avulso/form',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::formAvulso($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorio/avulso/salvar',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::salvarAvulso($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorio/avulso/listar',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::listarAvulsos($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorio/avulso/excluir',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorio::excluirAvulso($request));
	}
]);

// LABORATÓRIOS — CRUD
$obRouter->get('/painel/agenda/laboratorios',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorios::index($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorios',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorios::getInfo($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorios/form',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorios::getForm($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorios/salvar',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorios::salvar($request));
	}
]);

$obRouter->post('/painel/agenda/laboratorios/excluir',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaLaboratorios::excluir($request));
	}
]);

// HORÁRIOS — CRUD
$obRouter->get('/painel/agenda/horarios',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaHorarios::index($request));
	}
]);

$obRouter->post('/painel/agenda/horarios',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaHorarios::getInfo($request));
	}
]);

$obRouter->post('/painel/agenda/horarios/form',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaHorarios::getForm($request));
	}
]);

$obRouter->post('/painel/agenda/horarios/salvar',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaHorarios::salvar($request));
	}
]);

$obRouter->post('/painel/agenda/horarios/excluir',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaHorarios::excluir($request));
	}
]);

// DIÁRIO DE CLASSE
$obRouter->get('/painel/agenda/diario',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaDiario::index($request));
	}
]);

$obRouter->post('/painel/agenda/diario',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaDiario::getInfo($request));
	}
]);

$obRouter->post('/painel/agenda/diario/salvar',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaDiario::salvar($request));
	}
]);

$obRouter->post('/painel/agenda/diario/whatsapp',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaDiario::whatsapp($request));
	}
]);

// RELATÓRIO DE PRESENÇA
$obRouter->get('/painel/agenda/relatorio',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaRelatorio::index($request));
	}
]);

$obRouter->post('/painel/agenda/relatorio',[
	'middlewares' => ['required-admin-login'],
	function($request){
		return new Response(200, Admin\AgendaRelatorio::getInfo($request), 'application/json');
	}
]);
