<?php

namespace App\Controller\Admin;

use \App\Utils\View;
use \App\Model\Entity\Horarios as EntityHorarios;
use \App\Model\Entity\Laboratorios as EntityLabs;
use \App\Model\Db\Pagination;
use \App\Common\Helpers\TenantHelper;
use \App\Common\Helpers\AgendaHelper;

class AgendaHorarios extends Page {

	private static $dias = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'];

	public static function index($request) {
		$id_admin = parent::getIdAdminInt();
		AgendaHelper::garantirLabPadrao($id_admin);
		$labsHtml = '<option value="0">Todos os laboratórios</option>';
		$results = EntityLabs::getLabs('id_admin = '.(int)$id_admin.' AND ativo = 1', 'nome ASC');
		while ($lab = $results->fetchObject(EntityLabs::class)) {
			$labsHtml .= '<option value="'.(int)$lab->id.'">'.htmlspecialchars($lab->nome).'</option>';
		}
		$content = View::render('admin/modules/agenda/ag_horarios', ['labs_options' => $labsHtml]);
		return parent::getPanel('Horários', $content, 'agenda');
	}

	private static function getItens($request, &$obPagination) {
		$id_admin = parent::getIdAdminInt();
		$postVars = $request->getPostVars();
		$paginaAtual = $postVars['page'] ?? 1;
		$labId = (int)($postVars['laboratorio_id'] ?? 0);

		$where = 'horarios.id_admin = '.(int)$id_admin;
		if($labId > 0){
			$where .= ' AND horarios.laboratorio_id = '.$labId;
		}
		$innerJoin = 'LEFT JOIN laboratorios ON laboratorios.id = horarios.laboratorio_id';
		$fields = 'horarios.*, laboratorios.nome as lab_nome, laboratorios.qtd_computadores';

		$total = (int)EntityHorarios::getHorarios($where, null, null, 'COUNT(*) as qtd', $innerJoin)->fetchObject()->qtd;
		$obPagination = new Pagination($total, $paginaAtual, 15);
		$results = EntityHorarios::getHorarios($where, 'dia_semana ASC, inicio ASC', $obPagination->getLimit(), $fields, $innerJoin);

		$itens = '<button type="button" class="btn btn-success mb-3" onclick="horaForm(\'\',\'novo\')">Novo horário</button>';
		$itens .= '<div class="table-responsive"><table class="table table-striped"><thead><tr>
			<th>Dia</th><th>Início</th><th>Fim</th><th>Laboratório</th><th>Vagas</th><th>Ações</th></tr></thead><tbody>';

		while ($row = $results->fetchObject(EntityHorarios::class)) {
			$cap = (int)($row->qtd_computadores ?? 10);
			$itens .= '<tr>
				<td>'.(self::$dias[(int)$row->dia_semana] ?? '-').'</td>
				<td>'.$row->inicio.'</td><td>'.$row->final.'</td>
				<td>'.htmlspecialchars($row->lab_nome ?? '—').'</td>
				<td>'.(int)$row->vagas_ocupadas.' / '.$cap.'</td>
				<td>
					<button class="btn btn-sm btn-secondary" onclick="horaForm('.$row->id.',\'editar\')"><i class="far fa-edit"></i></button>
					<button class="btn btn-sm btn-danger" onclick="horaExcluir('.$row->id.')"><i class="far fa-trash-alt"></i></button>
				</td></tr>';
		}

		$itens .= '</tbody></table></div>';
		return $itens;
	}

	public static function getInfo($request) {
		try {
			$id_admin = parent::getIdAdminInt();
			AgendaHelper::garantirLabPadrao($id_admin);
			$obPagination = null;
			$postVars = $request->getPostVars();
			return json_encode([
				'itens'           => self::getItens($request, $obPagination),
				'laboratorio_id'  => (int)($postVars['laboratorio_id'] ?? 0),
				'pagination'      => parent::getPagination($request, $obPagination)
			]);
		} catch (\Throwable $e) {
			http_response_code(500);
			return json_encode(['erro' => $e->getMessage(), 'itens' => '', 'pagination' => '']);
		}
	}

	private static function selectLabs($id_admin, $selected = 0) {
		$html = '<select name="laboratorio_id" class="form-control" required><option value="">Selecione</option>';
		$results = EntityLabs::getLabs('id_admin = '.(int)$id_admin.' AND ativo = 1', 'nome ASC');
		while ($lab = $results->fetchObject(EntityLabs::class)) {
			$sel = ((int)$selected === (int)$lab->id) ? 'selected' : '';
			$html .= '<option '.$sel.' value="'.$lab->id.'">'.htmlspecialchars($lab->nome).' ('.$lab->qtd_computadores.' PCs)</option>';
		}
		$html .= '</select>';
		return $html;
	}

	public static function getForm($request) {
		$postVars = $request->getPostVars();
		$id_admin = parent::getIdAdminInt();
		$dados = [];
		$labPre = (int)($postVars['laboratorio_id'] ?? 0);

		if(($postVars['funcao'] ?? '') === 'editar'){
			$id = (int)($postVars['id'] ?? 0);
			if(!TenantHelper::pertence('horarios', $id, $id_admin)){
				return json_encode(['erro' => 'Registro não encontrado.']);
			}
			$dados = (array)EntityHorarios::getHorarioById($id);
		}

		$diasOpt = '';
		foreach(self::$dias as $num => $nome){
			$sel = ((int)($dados['dia_semana'] ?? 1) === $num) ? 'selected' : '';
			$diasOpt .= '<option '.$sel.' value="'.$num.'">'.$nome.'</option>';
		}

		$form = '<form id="formHora">
			<div class="modal-header"><h5 class="modal-title">Horário</h5>
			<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
			<div class="modal-body">
				<div id="response"></div>
				<div class="mb-3"><label>Laboratório</label>'.self::selectLabs($id_admin, $dados['laboratorio_id'] ?? $labPre).'</div>
				<div class="mb-3"><label>Dia da semana</label>
				<select name="dia_semana" class="form-control">'.$diasOpt.'</select></div>
				<div class="row"><div class="col-md-6 mb-3"><label>Início</label>
				<input type="time" name="inicio" class="form-control" value="'.($dados['inicio'] ?? '').'" required></div>
				<div class="col-md-6 mb-3"><label>Término</label>
				<input type="time" name="final" class="form-control" value="'.($dados['final'] ?? '').'" required></div></div>
			</div>
			<div class="modal-footer">
				<input type="hidden" name="id" value="'.(int)($dados['id'] ?? 0).'">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
				<button type="submit" class="btn btn-primary">Salvar</button>
			</div></form>';

		return json_encode(['form' => $form]);
	}

	public static function salvar($request) {
		$id_admin = parent::getIdAdminInt();
		$postVars = $request->getPostVars();
		$resposta = [];

		$labId = (int)($postVars['laboratorio_id'] ?? 0);
		if(!TenantHelper::pertence('laboratorios', $labId, $id_admin)){
			$resposta['erro'] = 'Laboratório inválido.';
			return json_encode($resposta);
		}

		$ob = new EntityHorarios;
		$ob->laboratorio_id = $labId;
		$ob->dia_semana = (int)($postVars['dia_semana'] ?? 1);
		$ob->inicio = $postVars['inicio'] ?? '';
		$ob->final = $postVars['final'] ?? '';

		if(!empty($postVars['id'])){
			$id = (int)$postVars['id'];
			if(!TenantHelper::pertence('horarios', $id, $id_admin)){
				$resposta['erro'] = 'Registro não encontrado.';
				return json_encode($resposta);
			}
			$ob->id = $id;
			$existente = EntityHorarios::getHorarioById($id);
			$ob->vagas_ocupadas = (int)$existente->vagas_ocupadas;
			$ob->atualizar();
		} else {
			$ob->id_admin = $id_admin;
			$ob->vagas_ocupadas = 0;
			$ob->cadastrar();
		}

		$resposta['sucesso'] = true;
		return json_encode($resposta);
	}

	public static function excluir($request) {
		$id = (int)($request->getPostVars()['id'] ?? 0);
		$id_admin = parent::getIdAdminInt();

		if(!TenantHelper::pertence('horarios', $id, $id_admin)){
			return 'Registro não encontrado.';
		}

		$ob = new EntityHorarios;
		$ob->id = $id;
		$ob->excluir();
		return true;
	}
}
