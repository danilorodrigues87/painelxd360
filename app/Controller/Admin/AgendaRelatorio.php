<?php

namespace App\Controller\Admin;

use \App\Utils\View;
use \App\Model\Entity\AgendaAulas as EntityAulas;
use \App\Model\Entity\Laboratorios as EntityLabs;
use \App\Model\Entity\Trilhas as EntityTrilhas;
use \App\Common\Helpers\DiarioWhatsappHelper;
use \App\Common\Helpers\RelatorioEnvelopeHelper;
use \App\Session\User\Login as SessionUser;
use PDO;

class AgendaRelatorio extends Page {

	public static function index($request) {
		$content = View::render('admin/modules/agenda/ag_relatorio', []);
		return parent::getPanel('Relatório de presença', $content, 'agenda', $request);
	}

	private static function optionsLabs(int $id_admin, int $selected): string {
		$html = '<option value="0">Todos os laboratórios</option>';
		$res = EntityLabs::getLabs('id_admin = '.(int)$id_admin.' AND ativo = 1', 'nome ASC');
		while ($lab = $res->fetchObject(EntityLabs::class)) {
			$sel = ($selected === (int)$lab->id) ? ' selected' : '';
			$html .= '<option value="'.(int)$lab->id.'"'.$sel.'>'.htmlspecialchars($lab->nome).'</option>';
		}
		return $html;
	}

	private static function optionsTrilhas(int $id_admin, int $selected): string {
		$html = '<option value="0">Todos os cursos</option>';
		$where = 'id_admin = '.(int)$id_admin;
		if (EntityTrilhas::temColunaAtivo()) {
			$where .= ' AND ativo = 1';
		}
		$res = EntityTrilhas::getTrilha($where, 'nome ASC');
		while ($t = $res->fetchObject(EntityTrilhas::class)) {
			$sel = ($selected === (int)$t->id) ? ' selected' : '';
			$html .= '<option value="'.(int)$t->id.'"'.$sel.'>'.htmlspecialchars($t->nome).'</option>';
		}
		return $html;
	}

	private static function optionsAlunos(int $id_admin, int $selected): string {
		$html = '<option value="0">Todos os alunos</option>';
		$fields = 'DISTINCT usuarios.id, usuarios.nome';
		$innerJoin = 'INNER JOIN usuarios ON usuarios.id = agenda_aulas.id_aluno';
		$where = 'agenda_aulas.id_admin = '.(int)$id_admin;
		$res = EntityAulas::getAulas($where, 'usuarios.nome ASC', null, $fields, $innerJoin);
		while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
			$id = (int)($row['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$sel = ($selected === $id) ? ' selected' : '';
			$html .= '<option value="'.$id.'"'.$sel.'>'.htmlspecialchars($row['nome'] ?? '').'</option>';
		}
		return $html;
	}

	private static function optionsStatus(string $selected): string {
		$html = '<option value="">Todos os status</option>';
		foreach (DiarioWhatsappHelper::statusPresencaValidos() as $val) {
			$sel = ($selected === $val) ? ' selected' : '';
			$html .= '<option value="'.$val.'"'.$sel.'>'.htmlspecialchars(DiarioWhatsappHelper::labelStatus($val)).'</option>';
		}
		return $html;
	}

	private static function getDadosItens($request): array {
		$id_admin = parent::getIdAdminInt();
		$postVars = $request->getPostVars();

		$de = trim((string)($postVars['de'] ?? ''));
		$ate = trim((string)($postVars['ate'] ?? ''));
		$idAluno = (int)($postVars['id_aluno'] ?? 0);
		$idTrilha = (int)($postVars['id_trilha'] ?? 0);
		$labFiltro = (int)($postVars['laboratorio_id'] ?? 0);
		$statusFiltro = trim((string)($postVars['status'] ?? ''));

		if ($de === '' || $ate === '') {
			$de = date('Y-m-01');
			$ate = date('Y-m-t');
		}

		$periodoLabel = DiarioWhatsappHelper::dataBr($de).' a '.DiarioWhatsappHelper::dataBr($ate);

		$conditions = ['agenda_aulas.id_admin = '.(int)$id_admin];
		$conditions[] = 'agenda_aulas.data_aula BETWEEN "'.$de.'" AND "'.$ate.'"';

		if ($idAluno > 0) {
			$conditions[] = 'agenda_aulas.id_aluno = '.$idAluno;
		}
		if ($idTrilha > 0) {
			$conditions[] = 'agenda_aulas.id_trilha = '.$idTrilha;
		}
		if ($labFiltro > 0) {
			$conditions[] = 'agenda_aulas.laboratorio_id = '.$labFiltro;
		}
		if ($statusFiltro !== '' && in_array($statusFiltro, DiarioWhatsappHelper::statusPresencaValidos(), true)) {
			if ($statusFiltro === 'aguardando') {
				$conditions[] = '(presencas.status IS NULL OR presencas.status = "" OR presencas.status = "aguardando")';
			} else {
				$conditions[] = 'presencas.status = "'.$statusFiltro.'"';
			}
		}

		$where = implode(' AND ', $conditions);

		$innerJoin = '
			INNER JOIN usuarios ON usuarios.id = agenda_aulas.id_aluno
			INNER JOIN trilhas ON trilhas.id = agenda_aulas.id_trilha
			INNER JOIN horarios ON horarios.id = agenda_aulas.id_horario
			LEFT JOIN laboratorios ON laboratorios.id = agenda_aulas.laboratorio_id
			LEFT JOIN presencas ON presencas.agenda_aula_id = agenda_aulas.id
		';

		$fields = 'agenda_aulas.data_aula, usuarios.nome as aluno, trilhas.nome as curso,
			laboratorios.nome as lab, horarios.inicio, horarios.final,
			COALESCE(NULLIF(presencas.status, ""), "aguardando") as presenca_status,
			presencas.observacao';

		$results = EntityAulas::getAulas(
			$where,
			'agenda_aulas.data_aula DESC, horarios.inicio ASC, usuarios.nome ASC',
			null,
			$fields,
			$innerJoin
		);

		$contagens = [
			'presente' => 0,
			'falta' => 0,
			'justificada' => 0,
			'reposicao' => 0,
			'aguardando' => 0,
		];
		$itens = '';
		$total = 0;

		while ($row = $results->fetch(PDO::FETCH_ASSOC)) {
			$total++;
			$status = DiarioWhatsappHelper::normalizarStatus($row['presenca_status'] ?? '');
			if (isset($contagens[$status])) {
				$contagens[$status]++;
			}
			$horario = DiarioWhatsappHelper::horarioBr($row['inicio'] ?? '', $row['final'] ?? '');
			$obs = trim((string)($row['observacao'] ?? ''));

			$itens .= '<tr>
				<td>'.htmlspecialchars(DiarioWhatsappHelper::dataBr($row['data_aula'] ?? '')).'</td>
				<td>'.htmlspecialchars($row['aluno'] ?? '').'</td>
				<td>'.htmlspecialchars($row['curso'] ?? '').'</td>
				<td>'.htmlspecialchars($row['lab'] ?? '—').'</td>
				<td>'.htmlspecialchars($horario).'</td>
				<td>'.htmlspecialchars(DiarioWhatsappHelper::labelStatus($status)).'</td>
				<td>'.htmlspecialchars($obs !== '' ? $obs : '—').'</td>
			</tr>';
		}

		$resumoLinha = 'Registros: '.$total
			.' · Presentes: '.$contagens['presente']
			.' · Faltas: '.$contagens['falta']
			.' · Reposições: '.$contagens['reposicao']
			.' · Justificadas: '.$contagens['justificada']
			.' · Aguardando: '.$contagens['aguardando'];

		$filtragem = '
<div class="container my-4 no-print d-print-none" id="relatorio-filtragem">
  <h3 class="mb-4">Opções de filtragem</h3>
  <form id="formBusca" method="post">
    <div class="row g-3">
      <div class="form-group col-md-3">
        <label for="de">De</label>
        <input type="date" id="de" name="de" value="'.htmlspecialchars($de).'" class="form-control">
      </div>
      <div class="form-group col-md-3">
        <label for="ate">Até</label>
        <input type="date" id="ate" name="ate" value="'.htmlspecialchars($ate).'" class="form-control">
      </div>
      <div class="form-group col-md-3">
        <label for="id_aluno">Aluno</label>
        <select class="form-control" id="id_aluno" name="id_aluno">'.self::optionsAlunos($id_admin, $idAluno).'</select>
      </div>
      <div class="form-group col-md-3">
        <label for="status">Status</label>
        <select class="form-control" id="status" name="status">'.self::optionsStatus($statusFiltro).'</select>
      </div>
      <div class="form-group col-md-3">
        <label for="laboratorio_id">Laboratório</label>
        <select class="form-control" id="laboratorio_id" name="laboratorio_id">'.self::optionsLabs($id_admin, $labFiltro).'</select>
      </div>
      <div class="form-group col-md-3">
        <label for="id_trilha">Curso</label>
        <select class="form-control" id="id_trilha" name="id_trilha">'.self::optionsTrilhas($id_admin, $idTrilha).'</select>
      </div>
    </div>
    <div class="d-flex justify-content-end mt-3 no-print d-print-none">
      <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Imprimir</button>
      <button type="button" class="btn btn-secondary ms-2" onclick="gerarPdfPresenca()">Gerar PDF</button>
      <button type="submit" class="btn btn-primary ms-3">Filtrar</button>
    </div>
  </form>
</div>';

		if ($total <= 0) {
			$conteudo = '<div class="alert alert-warning mt-2" role="alert">Nenhum registro encontrado para os filtros selecionados.</div>';
		} else {
			$conteudo = '
<div class="card-body relatorio-financeiro-conteudo">
  <div class="table-responsive">
    <table class="table table-striped relatorio-financeiro-tabela" width="100%" cellspacing="0">
      <thead>
        <tr>
          <th>Data</th>
          <th>Aluno</th>
          <th>Curso</th>
          <th>Lab</th>
          <th>Horário</th>
          <th>Status</th>
          <th>Obs.</th>
        </tr>
      </thead>
      <tbody>'.$itens.'</tbody>
    </table>
  </div>
</div>';
		}

		$userLoged = SessionUser::getUserLogedData();
		$escola = is_array($userLoged['escola'] ?? null) ? $userLoged['escola'] : [];
		$emitidoPor = trim((string)($userLoged['usuario']['nome'] ?? ''));
		$emitidoEm = date('d/m/Y').' às '.date('H:i');

		$table = RelatorioEnvelopeHelper::montar($conteudo, [
			'escola' => $escola,
			'titulo' => 'Relatório de Presença',
			'periodo' => $periodoLabel,
			'emitido_em' => $emitidoEm,
			'emitido_por' => $emitidoPor,
			'meta_linhas' => [$resumoLinha],
			'incluir_rodape' => false,
		]);

		$resumoTela = '
<div class="relatorio-tela-resumo no-print d-print-none mb-3 pb-2 border-bottom">
  <h4 class="h5 mb-1">Relatório de Presença</h4>
  <div class="relatorio-meta-linha">Período: '.htmlspecialchars($periodoLabel).'</div>
  <div class="relatorio-meta-linha">'.htmlspecialchars($resumoLinha).'</div>
</div>';

		return [
			'filtragem' => $filtragem,
			'itens' => $resumoTela.$table,
		];
	}

	public static function getInfo($request) {
		$dados = self::getDadosItens($request);
		return json_encode([
			'itens' => $dados['itens'],
			'filtragem' => $dados['filtragem'],
		], JSON_UNESCAPED_UNICODE);
	}
}
