<?php

namespace App\Controller\Admin;

use \App\Utils\View;
use \App\Model\Entity\Laboratorios as EntityLabs;
use \App\Model\Entity\Horarios as EntityHorarios;
use \App\Model\Db\Pagination;
use \App\Common\Helpers\TenantHelper;
use \App\Common\Helpers\AgendaHelper;

class AgendaLaboratorios extends Page {

	public static function index($request) {
		AgendaHelper::garantirSchemaV2();
		$content = View::render('admin/modules/agenda/ag_laboratorios', []);
		return parent::getPanel('Laboratórios', $content, 'agenda');
	}

	private static function getItens($request, &$obPagination) {
		$id_admin = parent::getIdAdminInt();
		$postVars = $request->getPostVars();
		$paginaAtual = $postVars['page'] ?? 1;

		$where = 'id_admin = '.(int)$id_admin;
		$total = (int)EntityLabs::getLabs($where, null, null, 'COUNT(*) as qtd')->fetchObject()->qtd;
		$obPagination = new Pagination($total, $paginaAtual, 10);
		$results = EntityLabs::getLabs($where, 'nome ASC', $obPagination->getLimit());

		$itens = '<div class="alert alert-info small mb-3">
			<strong>Como funciona:</strong> 1) Cadastre o laboratório → 2) Crie os <strong>horários</strong> vinculados a ele → 3) Em <strong>Agendamentos</strong>, aloque os alunos nos horários.
		</div>';
		$itens .= '<button type="button" class="btn btn-success mb-3" onclick="labForm(\'\',\'novo\')">Novo laboratório</button>';
		$itens .= '<div class="table-responsive"><table class="table table-striped"><thead><tr>
			<th>Nome</th><th>Computadores</th><th>Horários</th><th>Status</th><th>Ações</th></tr></thead><tbody>';

		while ($row = $results->fetchObject(EntityLabs::class)) {
			$qtdHorarios = (int)EntityHorarios::getHorarios(
				'horarios.id_admin = '.(int)$id_admin.' AND horarios.laboratorio_id = '.(int)$row->id,
				null, null, 'COUNT(*) as qtd'
			)->fetchObject()->qtd;

			$status = $row->ativo ? '<span class="badge bg-success">Ativo</span>' : '<span class="badge bg-secondary">Inativo</span>';
			$horariosBadge = $qtdHorarios > 0
				? '<span class="badge bg-primary">'.$qtdHorarios.'</span>'
				: '<span class="badge bg-warning text-dark">0 — cadastre horários</span>';

			$itens .= '<tr>
				<td>'.htmlspecialchars($row->nome).'</td>
				<td>'.(int)$row->qtd_computadores.'</td>
				<td>'.$horariosBadge.'</td>
				<td>'.$status.'</td>
				<td>
					<a class="btn btn-sm btn-primary" href="'.URL.'/painel/agenda/horarios?lab='.$row->id.'" title="Gerenciar horários"><i class="fa-regular fa-clock"></i></a>
					<button class="btn btn-sm btn-secondary" onclick="labForm('.$row->id.',\'editar\')"><i class="far fa-edit"></i></button>
					<button class="btn btn-sm btn-danger" onclick="labExcluir('.$row->id.')"><i class="far fa-trash-alt"></i></button>
				</td></tr>';
		}

		$itens .= '</tbody></table></div>';
		return $itens;
	}

	public static function getInfo($request) {
		AgendaHelper::garantirSchemaV2();
		$obPagination = null;
		return json_encode([
			'itens'      => self::getItens($request, $obPagination),
			'pagination' => parent::getPagination($request, $obPagination)
		]);
	}

	public static function getForm($request) {
		$postVars = $request->getPostVars();
		$dados = [];

		if(($postVars['funcao'] ?? '') === 'editar'){
			$id = (int)($postVars['id'] ?? 0);
			if(!TenantHelper::pertence('laboratorios', $id, parent::getIdAdminInt())){
				return json_encode(['erro' => 'Registro não encontrado.']);
			}
			$dados = (array)EntityLabs::getById($id);
		}

		$form = '<form id="formLab">
			<div class="modal-header"><h5 class="modal-title">Laboratório</h5>
			<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
			<div class="modal-body">
				<div id="response"></div>
				<div class="mb-3"><label>Nome</label>
				<input type="text" name="nome" class="form-control" value="'.htmlspecialchars($dados['nome'] ?? '').'" required></div>
				<div class="mb-3"><label>Qtd. computadores</label>
				<input type="number" name="qtd_computadores" class="form-control" min="1" max="99" value="'.(int)($dados['qtd_computadores'] ?? 10).'" required></div>
				<div class="mb-3"><label>Observação</label>
				<input type="text" name="observacao" class="form-control" value="'.htmlspecialchars($dados['observacao'] ?? '').'"></div>
				<div class="form-check">
				<input class="form-check-input" type="checkbox" name="ativo" value="1" '.((!isset($dados['ativo']) || $dados['ativo']) ? 'checked' : '').'>
				<label class="form-check-label">Ativo</label></div>
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

		$nome = trim($postVars['nome'] ?? '');
		$qtd = (int)($postVars['qtd_computadores'] ?? 0);

		if($nome === '' || $qtd < 1){
			$resposta['erro'] = 'Preencha nome e quantidade de computadores.';
			return json_encode($resposta);
		}

		$ob = new EntityLabs;
		$ob->nome = $nome;
		$ob->qtd_computadores = $qtd;
		$ob->observacao = trim($postVars['observacao'] ?? '');
		$ob->ativo = isset($postVars['ativo']) ? 1 : 0;

		if(!empty($postVars['id'])){
			$id = (int)$postVars['id'];
			if(!TenantHelper::pertence('laboratorios', $id, $id_admin)){
				$resposta['erro'] = 'Registro não encontrado.';
				return json_encode($resposta);
			}
			$ob->id = $id;
			$ob->atualizar();
		} else {
			$ob->id_admin = $id_admin;
			$ob->cadastrar();
		}

		$resposta['sucesso'] = true;
		$resposta['id'] = (int)($ob->id ?? $postVars['id'] ?? 0);
		$resposta['novo'] = empty($postVars['id']);
		return json_encode($resposta);
	}

	public static function excluir($request) {
		$id = (int)($request->getPostVars()['id'] ?? 0);
		$id_admin = parent::getIdAdminInt();

		if(!TenantHelper::pertence('laboratorios', $id, $id_admin)){
			return 'Registro não encontrado.';
		}

		$ob = new EntityLabs;
		$ob->id = $id;
		$ob->excluir();
		return true;
	}
}
