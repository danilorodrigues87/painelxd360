<?php

namespace App\Controller\Admin;

use \App\Utils\View;
use \App\Model\Entity\Horarios;
use \App\Model\Entity\AgendaPlano;
use \App\Model\Entity\Laboratorios;
use \App\Model\Entity\Trilhas as EntityTrilhas;
use \App\Model\Entity\Matriculas as EntityMatri;
use \App\Model\Db\Pagination;
use \App\Common\Helpers\AgendaHelper;
use \App\Common\Helpers\TenantHelper;
use PDO;

class AgendaLaboratorio extends Page {

	private static $dias = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'];

	private static function getDadosItem($request, &$obPagination) {
		$id_admin = parent::getIdAdminInt();
		AgendaHelper::garantirSchemaV2();
		AgendaHelper::garantirLabPadrao($id_admin);
		AgendaHelper::migrarLegado($id_admin);

		$data = date('Y-m-d');
		$dia = date('w', strtotime($data));
		if($dia == 0){
			$dia = 1;
		}

		$postVars = $request->getPostVars();
		$paginaAtual = $postVars['page'] ?? 1;
		$filtro = !empty($postVars['filtro']) ? (int)$postVars['filtro'] : $dia;
		$labId = (int)($postVars['laboratorio_id'] ?? 0);

		$where = 'horarios.id_admin = '.(int)$id_admin.' AND horarios.dia_semana = '.(int)$filtro;
		if($labId > 0){
			$where .= ' AND horarios.laboratorio_id = '.$labId;
		}
		$innerJoin = 'LEFT JOIN laboratorios ON laboratorios.id = horarios.laboratorio_id';
		$fields = 'horarios.*, laboratorios.nome as lab_nome, laboratorios.qtd_computadores';

		$quantidadeTotal = (int)Horarios::getHorarios($where, null, null, 'COUNT(*) as qtd', $innerJoin)->fetchObject()->qtd;
		$obPagination = new Pagination($quantidadeTotal, $paginaAtual, 6);
		$results = Horarios::getHorarios($where, 'inicio ASC', $obPagination->getLimit(), $fields, $innerJoin);

		$itens = '';
		while ($obDados = $results->fetchObject(Horarios::class)) {
			AgendaHelper::recalcularVagasHorario((int)$obDados->id);
			$cap = (int)($obDados->qtd_computadores ?? 10);
			$ocupadas = AgendaHelper::contarPlanosHorario((int)$obDados->id);
			$lab = htmlspecialchars($obDados->lab_nome ?? '—');

			$itens .= '<tr>
				<td>'.$lab.'</td>
				<td>'.$obDados->inicio.'</td>
				<td>'.$obDados->final.'</td>
				<td>'.$ocupadas.' / '.$cap.'</td>
				<td><a class="btn btn-secondary btn-sm" href="#" onclick="ver_info('.$obDados->id.', \'editar\')"><i class="fa-solid fa-user-clock"></i> Ver</a></td>
			</tr>';
		}

		$table = '<div class="card-body"><div class="table-responsive">
			<table class="table table-striped"><thead><tr>
			<th>Laboratório</th><th>Inicia</th><th>Termina</th><th>Vagas</th><th>Info</th>
			</tr></thead><tbody>'.$itens.'</tbody></table></div></div>';

		return ['table' => $table, 'filtro' => $filtro, 'laboratorio_id' => $labId];
	}

	private static function optionsLabsAtivos(int $id_admin): string {
		$html = '<option value="0">Todos os laboratórios</option>';
		$results = Laboratorios::getLabs('id_admin = '.$id_admin.' AND ativo = 1', 'nome ASC');
		while ($lab = $results->fetchObject(Laboratorios::class)) {
			$html .= '<option value="'.(int)$lab->id.'">'.htmlspecialchars($lab->nome).'</option>';
		}
		return $html;
	}

	public static function index($request) {
		$id_admin = parent::getIdAdminInt();
		AgendaHelper::garantirLabPadrao($id_admin);
		$content = View::render('admin/modules/agenda/ag_laboratorio', [
			'labs_options' => self::optionsLabsAtivos($id_admin),
		]);
		return parent::getPanel('Agendamentos', $content, 'agenda', $request);
	}

	public static function getInfo($request) {
		try {
		$dados = self::getDadosItem($request, $obPagination);
		return json_encode([
			'itens'           => $dados['table'],
			'filtro'          => $dados['filtro'],
			'laboratorio_id'  => $dados['laboratorio_id'],
			'pagination'      => parent::getPagination($request, $obPagination)
		]);
		} catch (\Throwable $e) {
			http_response_code(500);
			return json_encode(['erro' => $e->getMessage(), 'itens' => '', 'pagination' => '']);
		}
	}

	public static function verDados($request) {
		$postVars = $request->getPostVars();
		$id_admin = parent::getIdAdminInt();
		$listaItens = '';

		if(($postVars['funcao'] ?? '') === 'editar'){
			$idHorario = (int)($postVars['id'] ?? 0);
			if(!TenantHelper::pertence('horarios', $idHorario, $id_admin)){
				return 'Horário não encontrado.';
			}

			$innerJoin = 'INNER JOIN usuarios ON agenda_plano.id_aluno = usuarios.id
				INNER JOIN trilhas ON agenda_plano.id_trilha = trilhas.id';
			$fields = 'agenda_plano.id, usuarios.nome as aluno, trilhas.nome as trilha';

			$results = AgendaPlano::getPlanos(
				'agenda_plano.id_horario = '.$idHorario.' AND agenda_plano.ativo = 1',
				'usuarios.nome ASC', null, $fields, $innerJoin
			);

			while ($row = $results->fetch(PDO::FETCH_ASSOC)) {
				$listaItens .= '<tr>
					<td>'.htmlspecialchars($row['aluno']).'</td>
					<td>'.htmlspecialchars($row['trilha']).'</td>
					<td><button class="btn btn-sm btn-danger" onclick="excluir('.$row['id'].')"><i class="far fa-trash-alt"></i></button></td>
				</tr>';
			}
		}

		return '
		<div class="modal-header"><h5 class="modal-title">Alunos agendados</h5>
		<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
		<div class="modal-body"><div class="table-responsive"><table class="table table-striped">
		<thead><tr><th>Aluno</th><th>Curso</th><th></th></tr></thead>
		<tbody>'.($listaItens ?: '<tr><td colspan="3" class="text-muted text-center">Nenhum aluno neste horário</td></tr>').'</tbody>
		</table></div></div>';
	}

	public static function infoAluno($request) {
		$id_admin = parent::getIdAdminInt();
		$idAluno = (int)($request->getPostVars()['id_aluno'] ?? 0);

		$matricula = AgendaHelper::getMatriculaAtivaAluno($idAluno, $id_admin);
		$planos = AgendaHelper::contarPlanosAluno($idAluno, $id_admin);

		return json_encode([
			'aulas_semanais' => (int)($matricula['aulas_semanais'] ?? 0),
			'planos_ativos'  => $planos,
			'id_trilha'      => (int)($matricula['id_trilha'] ?? 0),
			'matricula_id'   => (int)($matricula['id'] ?? 0)
		]);
	}

	private static function selectLabs($id_admin, $selected = 0) {
		$html = '<select name="laboratorio_id" id="laboratorio_id" class="form-control" onchange="select_dia_semana()"><option value="0">Todos os laboratórios</option>';
		$results = Laboratorios::getLabs('id_admin = '.(int)$id_admin.' AND ativo = 1', 'nome ASC');
		while ($lab = $results->fetchObject(Laboratorios::class)) {
			$sel = ((int)$selected === (int)$lab->id) ? 'selected' : '';
			$html .= '<option '.$sel.' value="'.$lab->id.'">'.htmlspecialchars($lab->nome).' ('.$lab->qtd_computadores.' PCs)</option>';
		}
		$html .= '</select>';
		return $html;
	}

	public static function editar($request) {
		$postVars = $request->getPostVars();
		$id_admin = parent::getIdAdminInt();

		$dia_semana = (int)($postVars['dia_semana'] ?? 0);
		if($dia_semana <= 0 || $dia_semana > 6){
			$dia_semana = (int)date('w');
			if($dia_semana === 0){
				$dia_semana = 1;
			}
		}

		$innerJoin = 'INNER JOIN usuarios ON matriculas.id_aluno = usuarios.id';
		$fields = 'DISTINCT usuarios.id, usuarios.nome AS aluno';
		$resultsUser = EntityMatri::getMatriculas(
			'matriculas.status = 0 AND (matriculas.fim IS NULL OR matriculas.fim >= CURDATE()) AND matriculas.id_admin = '.$id_admin,
			'aluno ASC', null, $fields, $innerJoin
		);

		$optUsers = '<select class="form-control" name="id_aluno" id="id_aluno" onchange="infoAlunoPlano()" required>
			<option value="0">Selecione um aluno</option>';
		while ($row = $resultsUser->fetch(PDO::FETCH_ASSOC)) {
			$optUsers .= '<option value="'.(int)$row['id'].'">'.htmlspecialchars($row['aluno']).'</option>';
		}
		$optUsers .= '</select>';

		$optTrilhas = '<select class="form-control" name="id_trilha" id="id_trilha" required>
			<option value="0">Selecione a trilha</option>';
		$resultsTrilhas = EntityTrilhas::getTrilha('id_admin = '.$id_admin, 'nome ASC');
		while ($t = $resultsTrilhas->fetchObject(EntityTrilhas::class)) {
			$optTrilhas .= '<option value="'.(int)$t->id.'">'.htmlspecialchars($t->nome).'</option>';
		}
		$optTrilhas .= '</select>';

		$diasOpt = '';
		for($d = 1; $d <= 6; $d++){
			$sel = ($dia_semana == $d) ? 'selected' : '';
			$diasOpt .= '<option '.$sel.' value="'.$d.'">'.self::$dias[$d].'</option>';
		}

		$form = '<form id="form" method="POST">
		<div class="modal-header"><h5 class="modal-title">Novo horário no plano semanal</h5>
		<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
		<div class="modal-body">
			<div id="response"></div>
			<div id="info-plano" class="alert alert-info small d-none"></div>
			<div class="mb-3"><label>Aluno</label>'.$optUsers.'</div>
			<div class="mb-3"><label>Trilha</label>'.$optTrilhas.'</div>
			<div class="mb-3"><label>Laboratório</label>'.self::selectLabs($id_admin).'</div>
			<div class="mb-3"><label>Dia da semana</label>
			<select onchange="select_dia_semana()" name="dia_semana" id="dia_semana" class="form-control">'.$diasOpt.'</select></div>
			<div class="mb-3"><label>Horário</label><div id="horarios"></div></div>
		</div>
		<div class="modal-footer">
			<button type="button" id="btn-fechar-ag" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
			<button type="submit" class="btn btn-primary">Adicionar ao plano</button>
		</div></form>';

		return json_encode(['form' => $form, 'id_horario' => 0]);
	}

	public static function listarHorarios($request) {
		$postVars = $request->getPostVars();
		$id_admin = parent::getIdAdminInt();
		$dia_semana = (int)($postVars['dia_semana'] ?? 1);
		$id_horario = (int)($postVars['id_horario'] ?? 0);
		$labId = (int)($postVars['laboratorio_id'] ?? 0);

		$innerJoin = 'LEFT JOIN laboratorios ON laboratorios.id = horarios.laboratorio_id';
		$fields = 'horarios.id, horarios.inicio, horarios.final, horarios.vagas_ocupadas, laboratorios.nome as lab, laboratorios.qtd_computadores';

		$where = 'horarios.id_admin = '.$id_admin.' AND horarios.dia_semana = '.$dia_semana;
		if($labId > 0){
			$where .= ' AND horarios.laboratorio_id = '.$labId;
		}

		$resultHoras = Horarios::getHorarios($where, 'inicio ASC', null, $fields, $innerJoin);

		$opt = '<select class="form-control" name="id_horario" required><option value="0">Selecione o horário</option>';
		$temHorario = false;
		while ($h = $resultHoras->fetch(PDO::FETCH_ASSOC)) {
			$temHorario = true;
			$cap = (int)($h['qtd_computadores'] ?? 10);
			$ocup = AgendaHelper::contarPlanosHorario((int)$h['id']);
			$disp = $cap - $ocup;
			$sel = ($id_horario === (int)$h['id']) ? 'selected' : '';
			$lab = $h['lab'] ? ' · '.$h['lab'] : '';
			$opt .= '<option '.$sel.' value="'.$h['id'].'" '.($disp <= 0 ? 'disabled' : '').'>
				'.$h['inicio'].'–'.$h['final'].$lab.' ('.$disp.' vagas)</option>';
		}
		$opt .= '</select>';

		if(!$temHorario){
			$opt .= '<p class="text-muted small mt-2 mb-0">Nenhum horário neste dia/laboratório. <a href="'.URL.'/painel/agenda/horarios">Cadastre horários</a> primeiro.</p>';
		}

		return $opt;
	}

	public static function salvar($request) {
		$id_admin = parent::getIdAdminInt();
		$postVars = $request->getPostVars();

		$idAluno = (int)($postVars['id_aluno'] ?? 0);
		$idTrilha = (int)($postVars['id_trilha'] ?? 0);
		$idHorario = (int)($postVars['id_horario'] ?? 0);
		$diaSemana = (int)($postVars['dia_semana'] ?? 0);

		if($idAluno <= 0 || $idTrilha <= 0 || $idHorario <= 0 || $diaSemana <= 0){
			echo 'Preencha todos os campos.';
			return;
		}

		if(!TenantHelper::pertence('horarios', $idHorario, $id_admin)){
			echo 'Horário inválido.';
			return;
		}

		$matricula = AgendaHelper::getMatriculaAtivaAluno($idAluno, $id_admin);
		if(!$matricula){
			echo 'Aluno sem matrícula ativa.';
			return;
		}

		$limite = (int)$matricula['aulas_semanais'];
		$atual = AgendaHelper::contarPlanosAluno($idAluno, $id_admin);

		if($limite > 0 && $atual >= $limite){
			echo 'Este aluno já atingiu o limite de '.$limite.' aula(s) por semana.';
			return;
		}

		$existente = AgendaPlano::getPlanos(
			'id_admin = '.(int)$id_admin.' AND id_aluno = '.$idAluno.' AND id_horario = '.$idHorario,
			'id DESC',
			1
		)->fetchObject(AgendaPlano::class);

		if ($existente instanceof AgendaPlano && (int)$existente->ativo === 1) {
			echo 'Este aluno já está neste horário.';
			return;
		}

		$cap = AgendaHelper::getCapacidadeHorario($idHorario);
		$ocup = AgendaHelper::contarPlanosHorario($idHorario);
		if ($ocup >= $cap) {
			echo 'Não há vagas disponíveis neste horário.';
			return;
		}

		if ($existente instanceof AgendaPlano) {
			$existente->reativar([
				'matricula_id' => (int)$matricula['id'],
				'id_trilha' => $idTrilha,
				'dia_semana' => $diaSemana,
				'data_inicio' => date('Y-m-d'),
			]);
		} else {
			$ob = new AgendaPlano;
			$ob->id_admin = $id_admin;
			$ob->matricula_id = (int)$matricula['id'];
			$ob->id_aluno = $idAluno;
			$ob->id_trilha = $idTrilha;
			$ob->id_horario = $idHorario;
			$ob->dia_semana = $diaSemana;
			$ob->data_inicio = date('Y-m-d');
			$ob->cadastrar();
		}

		AgendaHelper::recalcularVagasHorario($idHorario);
		echo 'salvo';
	}

	public static function excluir($request) {
		$id = (int)($request->getPostVars()['id'] ?? 0);
		$id_admin = parent::getIdAdminInt();

		if(!TenantHelper::pertence('agenda_plano', $id, $id_admin)){
			return '0';
		}

		$plano = AgendaPlano::getById($id, $id_admin);
		if(!$plano){
			return '0';
		}

		$plano->inativar();
		AgendaHelper::recalcularVagasHorario((int)$plano->id_horario);
		return '1';
	}

	/** Modal: agendar reposição (avulso) */
	public static function formAvulso($request) {
		$id_admin = parent::getIdAdminInt();
		$hoje = date('Y-m-d');

		$innerJoin = 'INNER JOIN usuarios ON matriculas.id_aluno = usuarios.id';
		$fields = 'DISTINCT usuarios.id, usuarios.nome AS aluno';
		$resultsUser = EntityMatri::getMatriculas(
			'matriculas.status = 0 AND (matriculas.fim IS NULL OR matriculas.fim >= CURDATE()) AND matriculas.id_admin = '.$id_admin,
			'aluno ASC', null, $fields, $innerJoin
		);
		$optUsers = '<select class="form-control" name="id_aluno" id="av_id_aluno" onchange="infoAlunoAvulso()" required>
			<option value="0">Selecione um aluno</option>';
		while ($row = $resultsUser->fetch(PDO::FETCH_ASSOC)) {
			$optUsers .= '<option value="'.(int)$row['id'].'">'.htmlspecialchars($row['aluno']).'</option>';
		}
		$optUsers .= '</select>';

		$optTrilhas = '<select class="form-control" name="id_trilha" id="av_id_trilha" required>
			<option value="0">Selecione a trilha</option>';
		$resultsTrilhas = EntityTrilhas::getTrilha('id_admin = '.$id_admin, 'nome ASC');
		while ($t = $resultsTrilhas->fetchObject(EntityTrilhas::class)) {
			$optTrilhas .= '<option value="'.(int)$t->id.'">'.htmlspecialchars($t->nome).'</option>';
		}
		$optTrilhas .= '</select>';

		$diaW = (int)date('w');
		if ($diaW === 0) {
			$diaW = 1;
		}

		$form = '<form id="form-avulso" method="POST">
		<div class="modal-header"><h5 class="modal-title">Agendar reposição (avulso)</h5>
		<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
		<div class="modal-body">
			<div id="response-avulso"></div>
			<p class="small text-muted">Libera a cota de aulas no portal LMS <strong>somente nesta data e horário</strong> (não altera o plano semanal).</p>
			<div class="mb-3"><label>Aluno</label>'.$optUsers.'</div>
			<div class="mb-3"><label>Trilha</label>'.$optTrilhas.'</div>
			<div class="mb-3"><label>Data</label>
				<input type="date" class="form-control" name="data" id="av_data" value="'.$hoje.'" required onchange="carregarHorariosAvulso()">
			</div>
			<div class="mb-3"><label>Laboratório</label>'.str_replace('id="laboratorio_id"', 'id="av_laboratorio_id" onchange="carregarHorariosAvulso()"', self::selectLabs($id_admin)).'</div>
			<input type="hidden" name="dia_semana" id="av_dia_semana" value="'.$diaW.'">
			<div class="mb-3"><label>Horário</label><div id="av_horarios"></div></div>
			<div class="mb-3"><label>Aulas liberadas nesta sessão</label>
				<input type="number" class="form-control" name="aulas_cota" min="1" max="10" value="2">
			</div>
			<div class="mb-3"><label>Motivo (opcional)</label>
				<input type="text" class="form-control" name="motivo" maxlength="255" placeholder="Ex.: reposição de falta">
			</div>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
			<button type="submit" class="btn btn-warning">Salvar reposição</button>
		</div></form>';

		return $form;
	}

	public static function listarHorariosAvulso($request) {
		// Reusa listarHorarios — mesmo formato
		return self::listarHorarios($request);
	}

	public static function salvarAvulso($request) {
		$id_admin = parent::getIdAdminInt();
		$post = $request->getPostVars();
		$user = parent::getIdAdmin();
		$criadoPor = (int)($user['usuario']['id'] ?? 0) ?: null;

		$idAluno = (int)($post['id_aluno'] ?? 0);
		$idTrilha = (int)($post['id_trilha'] ?? 0);
		$idHorario = (int)($post['id_horario'] ?? 0);
		$data = trim((string)($post['data'] ?? ''));
		$cota = max(1, min(10, (int)($post['aulas_cota'] ?? 2)));
		$motivo = trim((string)($post['motivo'] ?? ''));

		if ($idAluno <= 0 || $idTrilha <= 0 || $idHorario <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
			echo 'Preencha aluno, trilha, data e horário.';
			return;
		}
		if (!TenantHelper::pertence('horarios', $idHorario, $id_admin)) {
			echo 'Horário inválido.';
			return;
		}
		$matricula = AgendaHelper::getMatriculaAtivaAluno($idAluno, $id_admin);
		if (!$matricula) {
			echo 'Aluno sem matrícula ativa.';
			return;
		}

		$ob = new \App\Model\Entity\AgendaAvulso();
		$ob->id_admin = $id_admin;
		$ob->id_aluno = $idAluno;
		$ob->matricula_id = (int)$matricula['id'];
		$ob->id_trilha = $idTrilha;
		$ob->id_horario = $idHorario;
		$ob->data = $data;
		$ob->aulas_cota = $cota;
		$ob->motivo = $motivo !== '' ? $motivo : null;
		$ob->ativo = 1;
		$ob->criado_por = $criadoPor;
		$ob->cadastrar();
		echo 'salvo';
	}

	public static function listarAvulsos($request) {
		$id_admin = parent::getIdAdminInt();
		$post = $request->getPostVars();
		$data = trim((string)($post['data'] ?? date('Y-m-d')));
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
			$data = date('Y-m-d');
		}

		if (!\App\Common\Helpers\LmsAgendaAcessoHelper::tabelasExistem()) {
			return json_encode([
				'html' => '<p class="text-danger small mb-0">Tabelas de reposição ainda não existem. Rode o SQL <code>database/lms_agenda_acesso.sql</code> no phpMyAdmin.</p>'
			]);
		}

		$rows = \App\Model\Entity\AgendaAvulso::getAll(
			'agenda_avulso.id_admin = '.(int)$id_admin.' AND agenda_avulso.ativo = 1 AND agenda_avulso.data = "'.addslashes($data).'"',
			'horarios.inicio ASC',
			null,
			'agenda_avulso.*, usuarios.nome AS aluno, trilhas.nome AS trilha, horarios.inicio, horarios.final, laboratorios.nome AS lab',
			'INNER JOIN usuarios ON usuarios.id = agenda_avulso.id_aluno
			 INNER JOIN trilhas ON trilhas.id = agenda_avulso.id_trilha
			 INNER JOIN horarios ON horarios.id = agenda_avulso.id_horario
			 LEFT JOIN laboratorios ON laboratorios.id = horarios.laboratorio_id'
		);

		$html = '<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr>
			<th>Aluno</th><th>Trilha</th><th>Horário</th><th>Cota</th><th>Motivo</th><th></th>
			</tr></thead><tbody>';
		$n = 0;
		while ($r = $rows->fetch(\PDO::FETCH_ASSOC)) {
			$n++;
			$html .= '<tr>
				<td>'.htmlspecialchars($r['aluno']).'</td>
				<td>'.htmlspecialchars($r['trilha']).'</td>
				<td>'.htmlspecialchars(substr($r['inicio'], 0, 5).'–'.substr($r['final'], 0, 5)).($r['lab'] ? ' · '.htmlspecialchars($r['lab']) : '').'</td>
				<td>'.(int)$r['aulas_cota'].'</td>
				<td>'.htmlspecialchars((string)($r['motivo'] ?? '—')).'</td>
				<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="excluirAvulso('.(int)$r['id'].')">Remover</button></td>
			</tr>';
		}
		if ($n === 0) {
			$html .= '<tr><td colspan="6" class="text-muted text-center">Nenhuma reposição nesta data.</td></tr>';
		}
		$html .= '</tbody></table></div>';
		return json_encode(['html' => $html, 'data' => $data]);
	}

	public static function excluirAvulso($request) {
		$id = (int)($request->getPostVars()['id'] ?? 0);
		$id_admin = parent::getIdAdminInt();
		$ob = \App\Model\Entity\AgendaAvulso::getById($id, $id_admin);
		if (!$ob) {
			return 'Registro não encontrado.';
		}
		$ob->inativar();
		return true;
	}
}
