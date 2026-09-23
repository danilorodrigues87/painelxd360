<?php

namespace App\Controller\Admin;

use \App\Utils\View;
use \App\Model\Entity\CrmTarefasListas as EntityListas;
use \App\Model\Entity\CrmTarefasCartoes as EntityCartoes;
use \App\Model\Entity\CrmTarefasChecklists as EntityChecklists;
use \App\Model\Entity\CrmTarefasComentarios as EntityComentarios;
use \App\Model\Entity\User as EntityUser;
use \App\Common\Helpers\DateTimeHelper;
use \App\Common\Helpers\TenantHelper;

class CrmTarefas extends Page{

	private static function obterLista($id, $id_admin){
		$obLista = EntityListas::getListaById($id, $id_admin);
		return ($obLista instanceof EntityListas) ? $obLista : null;
	}

	private static function obterCartao($id, $id_admin){
		$obCartao = EntityCartoes::getCartaoById($id);
		if(!$obCartao instanceof EntityCartoes){
			return null;
		}
		if(!self::obterLista((int)$obCartao->lista_id, $id_admin)){
			return null;
		}
		return $obCartao;
	}

	public static function index($request){
		$content = View::render('admin/modules/crm/tarefas',[]);
		return parent::getPanel('Tarefas',$content,'CRM');
	}

	public static function getInfo($request){

		$id_admin = parent::getIdAdminInt();
		$listas = [];

		$resultsListas = EntityListas::getListas('id_admin = '.$id_admin,'posicao ASC');

		while ($obLista = $resultsListas->fetchObject(EntityListas::class)) {

			$cartoes = [];

			$resultsCartoes = EntityCartoes::getCartoes(
				'lista_id = '.(int)$obLista->id,
				'posicao ASC'
			);

			while ($obCartao = $resultsCartoes->fetchObject(EntityCartoes::class)) {

				$resumoChecklist = EntityChecklists::getResumoPorCartao($obCartao->id);

				$cartoes[] = [
					'id'                  => $obCartao->id,
					'titulo'              => $obCartao->titulo,
					'descricao_resumo'    => self::resumirTexto($obCartao->descricao),
					'checklist_total'     => $resumoChecklist['total'],
					'checklist_concluidos'=> $resumoChecklist['concluidos'],
					'data_cadastro'       => DateTimeHelper::databr($obCartao->data_cadastro)
				];
			}

			$listas[] = [
				'id'      => $obLista->id,
				'titulo'  => $obLista->titulo,
				'posicao' => $obLista->posicao,
				'cartoes' => $cartoes
			];
		}

		return json_encode(['listas' => $listas]);
	}

	public static function salvarLista($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$titulo = trim($postVars['titulo'] ?? '');

		if($titulo == ''){
			$resposta['erro'] = 'Informe o título da lista.';
			return json_encode($resposta);
		}

		$obLista = new EntityListas;
		$obLista->id_admin = parent::getIdAdminInt();
		$obLista->titulo = $titulo;
		$obLista->cadastrar();

		$resposta['sucesso'] = true;
		$resposta['id']      = $obLista->id;

		return json_encode($resposta);
	}

	public static function atualizarLista($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id     = isset($postVars['id']) ? (int)$postVars['id'] : 0;
		$titulo = trim($postVars['titulo'] ?? '');

		if($id <= 0){
			$resposta['erro'] = 'Lista inválida.';
			return json_encode($resposta);
		}

		if($titulo == ''){
			$resposta['erro'] = 'Informe o título da lista.';
			return json_encode($resposta);
		}

		$id_admin = parent::getIdAdminInt();
		$obLista = self::obterLista($id, $id_admin);

		if(!$obLista){
			$resposta['erro'] = 'Lista não encontrada.';
			return json_encode($resposta);
		}

		$obLista->titulo = $titulo;
		$obLista->atualizar();

		$resposta['sucesso'] = true;
		return json_encode($resposta);
	}

	public static function excluirLista($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id = isset($postVars['id']) ? (int)$postVars['id'] : 0;

		if($id <= 0){
			$resposta['erro'] = 'Lista inválida.';
			return json_encode($resposta);
		}

		$id_admin = parent::getIdAdminInt();

		if(!self::obterLista($id, $id_admin)){
			$resposta['erro'] = 'Lista não encontrada.';
			return json_encode($resposta);
		}

		EntityListas::excluir($id);

		$resposta['sucesso'] = true;
		return json_encode($resposta);
	}

	public static function salvarCartao($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$listaId = isset($postVars['lista_id']) ? (int)$postVars['lista_id'] : 0;
		$titulo  = trim($postVars['titulo'] ?? '');

		if($listaId <= 0){
			$resposta['erro'] = 'Lista inválida.';
			return json_encode($resposta);
		}

		if($titulo == ''){
			$resposta['erro'] = 'Informe o título do cartão.';
			return json_encode($resposta);
		}

		$id_admin = parent::getIdAdminInt();
		$obLista = self::obterLista($listaId, $id_admin);

		if(!$obLista){
			$resposta['erro'] = 'Lista não encontrada.';
			return json_encode($resposta);
		}

		$obCartao = new EntityCartoes;
		$obCartao->lista_id = $listaId;
		$obCartao->titulo   = $titulo;
		$obCartao->cadastrar();

		$resposta['sucesso'] = true;
		$resposta['id']      = $obCartao->id;

		return json_encode($resposta);
	}

	public static function atualizarCartao($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id      = isset($postVars['id']) ? (int)$postVars['id'] : 0;
		$titulo  = trim($postVars['titulo'] ?? '');
		$descricao = $postVars['descricao'] ?? null;

		if($id <= 0){
			$resposta['erro'] = 'Cartão inválido.';
			return json_encode($resposta);
		}

		$id_admin = parent::getIdAdminInt();
		$obCartao = self::obterCartao($id, $id_admin);

		if(!$obCartao){
			$resposta['erro'] = 'Cartão não encontrado.';
			return json_encode($resposta);
		}

		if($titulo !== ''){
			$obCartao->titulo = $titulo;
		}

		if($descricao !== null){
			$obCartao->descricao = trim($descricao) !== '' ? trim($descricao) : null;
		}

		$obCartao->atualizar();

		$resposta['sucesso'] = true;
		return json_encode($resposta);
	}

	public static function excluirCartao($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id = isset($postVars['id']) ? (int)$postVars['id'] : 0;

		if($id <= 0){
			$resposta['erro'] = 'Cartão inválido.';
			return json_encode($resposta);
		}

		$id_admin = parent::getIdAdminInt();

		if(!self::obterCartao($id, $id_admin)){
			$resposta['erro'] = 'Cartão não encontrado.';
			return json_encode($resposta);
		}

		EntityCartoes::excluir($id);

		$resposta['sucesso'] = true;
		return json_encode($resposta);
	}

	public static function atualizarPosicoes($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$posicoes = $postVars['posicoes'] ?? null;

		if(!is_array($posicoes) || count($posicoes) === 0){
			$resposta['erro'] = 'Nenhuma posição informada.';
			return json_encode($resposta);
		}

		$id_admin = parent::getIdAdminInt();

		foreach($posicoes as $item){

			$id      = isset($item['id']) ? (int)$item['id'] : 0;
			$listaId = isset($item['lista_id']) ? (int)$item['lista_id'] : 0;
			$posicao = isset($item['posicao']) ? (int)$item['posicao'] : 0;

			if($id <= 0 || $listaId <= 0){
				continue;
			}

			$obCartao = self::obterCartao($id, $id_admin);

			if(!$obCartao){
				continue;
			}

			if(!self::obterLista($listaId, $id_admin)){
				continue;
			}

			$obCartao->lista_id = $listaId;
			$obCartao->posicao  = $posicao;
			$obCartao->atualizarPosicao();
		}

		$resposta['sucesso'] = true;
		return json_encode($resposta);
	}

	public static function getDetalhes($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id = isset($postVars['id']) ? (int)$postVars['id'] : 0;

		if($id <= 0){
			$resposta['erro'] = 'Cartão inválido.';
			return json_encode($resposta);
		}

		$id_admin = parent::getIdAdminInt();
		$obCartao = self::obterCartao($id, $id_admin);

		if(!$obCartao){
			$resposta['erro'] = 'Cartão não encontrado.';
			return json_encode($resposta);
		}

		$checklist = [];
		$resultsChecklist = EntityChecklists::getItens('cartao_id = '.(int)$id,'id ASC');

		while ($obItem = $resultsChecklist->fetchObject(EntityChecklists::class)) {
			$checklist[] = [
				'id'         => $obItem->id,
				'item_texto' => $obItem->item_texto,
				'concluido'  => (int)$obItem->concluido
			];
		}

		$resumo = EntityChecklists::getResumoPorCartao($id);
		$percentual = $resumo['total'] > 0
			? round(($resumo['concluidos'] / $resumo['total']) * 100)
			: 0;

		$resposta['cartao'] = [
			'id'        => $obCartao->id,
			'lista_id'  => $obCartao->lista_id,
			'titulo'    => $obCartao->titulo,
			'descricao' => $obCartao->descricao ?? '',
			'data_cadastro' => DateTimeHelper::databr($obCartao->data_cadastro)
		];

		$resposta['checklist']   = $checklist;
		$resposta['progresso']   = $percentual;
		$resposta['comentarios'] = self::montarComentarios($id);

		return json_encode($resposta);
	}

	public static function salvarChecklistItem($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$cartaoId  = isset($postVars['cartao_id']) ? (int)$postVars['cartao_id'] : 0;
		$itemTexto = trim($postVars['item_texto'] ?? '');

		if($cartaoId <= 0){
			$resposta['erro'] = 'Cartão inválido.';
			return json_encode($resposta);
		}

		if($itemTexto == ''){
			$resposta['erro'] = 'Informe o texto do item.';
			return json_encode($resposta);
		}

		$id_admin = parent::getIdAdminInt();
		$obCartao = self::obterCartao($cartaoId, $id_admin);

		if(!$obCartao){
			$resposta['erro'] = 'Cartão não encontrado.';
			return json_encode($resposta);
		}

		$obItem = new EntityChecklists;
		$obItem->cartao_id  = $cartaoId;
		$obItem->item_texto = $itemTexto;
		$obItem->cadastrar();

		$resumo = EntityChecklists::getResumoPorCartao($cartaoId);
		$percentual = $resumo['total'] > 0
			? round(($resumo['concluidos'] / $resumo['total']) * 100)
			: 0;

		$resposta['sucesso'] = true;
		$resposta['item'] = [
			'id'         => $obItem->id,
			'item_texto' => $itemTexto,
			'concluido'  => 0
		];
		$resposta['progresso'] = $percentual;

		return json_encode($resposta);
	}

	public static function toggleChecklistItem($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id        = isset($postVars['id']) ? (int)$postVars['id'] : 0;
		$concluido = isset($postVars['concluido']) ? (int)$postVars['concluido'] : 0;

		if($id <= 0){
			$resposta['erro'] = 'Item inválido.';
			return json_encode($resposta);
		}

		$obItem = EntityChecklists::getItemById($id);

		if(!$obItem instanceof EntityChecklists){
			$resposta['erro'] = 'Item não encontrado.';
			return json_encode($resposta);
		}

		if(!self::obterCartao((int)$obItem->cartao_id, parent::getIdAdminInt())){
			$resposta['erro'] = 'Item não encontrado.';
			return json_encode($resposta);
		}

		$obItem->concluido = $concluido ? 1 : 0;
		$obItem->atualizarConcluido();

		$resumo = EntityChecklists::getResumoPorCartao($obItem->cartao_id);
		$percentual = $resumo['total'] > 0
			? round(($resumo['concluidos'] / $resumo['total']) * 100)
			: 0;

		$resposta['sucesso']   = true;
		$resposta['progresso'] = $percentual;
		$resposta['resumo']    = $resumo;

		return json_encode($resposta);
	}

	public static function excluirChecklistItem($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$id = isset($postVars['id']) ? (int)$postVars['id'] : 0;

		if($id <= 0){
			$resposta['erro'] = 'Item inválido.';
			return json_encode($resposta);
		}

		$obItem = EntityChecklists::getItemById($id);

		if(!$obItem instanceof EntityChecklists){
			$resposta['erro'] = 'Item não encontrado.';
			return json_encode($resposta);
		}

		if(!self::obterCartao((int)$obItem->cartao_id, parent::getIdAdminInt())){
			$resposta['erro'] = 'Item não encontrado.';
			return json_encode($resposta);
		}

		$cartaoId = $obItem->cartao_id;
		EntityChecklists::excluir($id);

		$resumo = EntityChecklists::getResumoPorCartao($cartaoId);
		$percentual = $resumo['total'] > 0
			? round(($resumo['concluidos'] / $resumo['total']) * 100)
			: 0;

		$resposta['sucesso']   = true;
		$resposta['progresso'] = $percentual;
		$resposta['resumo']    = $resumo;

		return json_encode($resposta);
	}

	public static function salvarComentario($request){

		$postVars = $request->getPostVars();
		$resposta = [];

		$cartaoId   = isset($postVars['cartao_id']) ? (int)$postVars['cartao_id'] : 0;
		$comentario = trim($postVars['comentario'] ?? '');

		if($cartaoId <= 0){
			$resposta['erro'] = 'Cartão inválido.';
			return json_encode($resposta);
		}

		if($comentario == ''){
			$resposta['erro'] = 'Digite um comentário antes de salvar.';
			return json_encode($resposta);
		}

		$id_admin = parent::getIdAdminInt();
		$obCartao = self::obterCartao($cartaoId, $id_admin);

		if(!$obCartao){
			$resposta['erro'] = 'Cartão não encontrado.';
			return json_encode($resposta);
		}

		$idUsuario = parent::getIdAdmin()['usuario']['id'];

		$obComentario = new EntityComentarios;
		$obComentario->cartao_id  = $cartaoId;
		$obComentario->usuario_id = $idUsuario;
		$obComentario->comentario = $comentario;
		$obComentario->cadastrar();

		$resposta['sucesso']     = true;
		$resposta['comentarios'] = self::montarComentarios($cartaoId);

		return json_encode($resposta);
	}

	private static function resumirTexto($texto, $limite = 80){
		$texto = trim(strip_tags($texto ?? ''));
		if($texto == ''){
			return '';
		}
		if(mb_strlen($texto) <= $limite){
			return $texto;
		}
		return mb_substr($texto, 0, $limite).'...';
	}

	private static function montarComentarios($cartaoId){

		$results = EntityComentarios::getComentarios(
			'cartao_id = '.(int)$cartaoId,
			'data_cadastro DESC'
		);

		$itens = '';

		while ($obComentario = $results->fetchObject(EntityComentarios::class)) {

			$obUser = EntityUser::getUserById($obComentario->usuario_id);
			$nomeUsuario = ($obUser instanceof EntityUser) ? $obUser->nome : 'Usuário';

			$dataFormatada = DateTimeHelper::databr($obComentario->data_cadastro);
			$horaFormatada = DateTimeHelper::extrairHorario($obComentario->data_cadastro);

			$itens .= '
			<div class="tarefa-comentario-item">
				<div class="d-flex justify-content-between align-items-start mb-1">
					<strong class="small">'.htmlspecialchars($nomeUsuario).'</strong>
					<small class="text-muted">'.$dataFormatada.' '.$horaFormatada.'</small>
				</div>
				<p class="small mb-0">'.nl2br(htmlspecialchars($obComentario->comentario)).'</p>
			</div>';
		}

		if($itens == ''){
			return '<p class="text-muted small mb-0">Nenhum comentário ainda.</p>';
		}

		return $itens;
	}

}
