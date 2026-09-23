<?php

use \App\Http\Response;
use \App\Controller\Admin;

//ROTA DO QUADRO DE TAREFAS
$obRouter->get('/painel/crm/tarefas',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::index($request));
	}
]);

//ROTA LISTAGEM DO QUADRO (LISTAS + CARTÕES)
$obRouter->post('/painel/crm/tarefas',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::getInfo($request));
	}
]);

//ROTA CRIAR LISTA
$obRouter->post('/painel/crm/tarefas/lista/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::salvarLista($request));
	}
]);

//ROTA ATUALIZAR LISTA
$obRouter->post('/painel/crm/tarefas/lista/atualizar',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::atualizarLista($request));
	}
]);

//ROTA EXCLUIR LISTA
$obRouter->post('/painel/crm/tarefas/lista/excluir',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::excluirLista($request));
	}
]);

//ROTA CRIAR CARTÃO
$obRouter->post('/painel/crm/tarefas/cartao/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::salvarCartao($request));
	}
]);

//ROTA ATUALIZAR CARTÃO
$obRouter->post('/painel/crm/tarefas/cartao/atualizar',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::atualizarCartao($request));
	}
]);

//ROTA EXCLUIR CARTÃO
$obRouter->post('/painel/crm/tarefas/cartao/excluir',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::excluirCartao($request));
	}
]);

//ROTA ATUALIZAR POSIÇÕES (DRAG AND DROP)
$obRouter->post('/painel/crm/tarefas/cartao/posicao',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::atualizarPosicoes($request));
	}
]);

//ROTA DETALHES DO CARTÃO (MODAL)
$obRouter->post('/painel/crm/tarefas/detalhes',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::getDetalhes($request));
	}
]);

//ROTA ADICIONAR ITEM DE CHECKLIST
$obRouter->post('/painel/crm/tarefas/checklist/save',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::salvarChecklistItem($request));
	}
]);

//ROTA MARCAR/DESMARCAR ITEM DE CHECKLIST
$obRouter->post('/painel/crm/tarefas/checklist/toggle',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::toggleChecklistItem($request));
	}
]);

//ROTA EXCLUIR ITEM DE CHECKLIST
$obRouter->post('/painel/crm/tarefas/checklist/excluir',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::excluirChecklistItem($request));
	}
]);

//ROTA SALVAR COMENTÁRIO NA TAREFA
$obRouter->post('/painel/crm/tarefas/comentario',[
	'middlewares' => [
		'required-admin-login'
	],
	function($request){
		return new Response(200,Admin\CrmTarefas::salvarComentario($request));
	}
]);
