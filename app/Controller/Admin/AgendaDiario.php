<?php

namespace App\Controller\Admin;

use \App\Utils\View;
use \App\Model\Entity\AgendaAulas as EntityAulas;
use \App\Model\Entity\Presencas as EntityPresencas;
use \App\Model\Entity\Laboratorios as EntityLabs;
use \App\Model\Entity\EscolaIntegracoes;
use \App\Common\Helpers\AgendaHelper;
use \App\Common\Helpers\DiarioWhatsappHelper;
use \App\Common\Helpers\ModuleGateHelper;
use \App\Common\Communication\CampanhaWorker;
use \App\Common\Communication\WhatsappEscolaService;
use \App\Common\Helpers\TenantHelper;
use PDO;

class AgendaDiario extends Page {

	private static function statusOptionsHtml(string $status): string {
		$labels = [
			'aguardando'  => 'Aguardando',
			'presente'    => 'Presente',
			'falta'       => 'Falta',
			'justificada' => 'Justificada',
			'reposicao'   => 'Reposição',
		];
		if ($status === '') {
			$status = 'aguardando';
		}
		$html = '';
		foreach ($labels as $val => $label) {
			$sel = ($status === $val) ? ' selected' : '';
			$html .= '<option value="'.$val.'"'.$sel.'>'.htmlspecialchars($label).'</option>';
		}
		return $html;
	}

	public static function index($request) {
		$content = View::render('admin/modules/agenda/ag_diario', []);
		return parent::getPanel('Diário', $content, 'agenda', $request);
	}

	public static function getInfo($request) {
		$id_admin = parent::getIdAdminInt();
		$postVars = $request->getPostVars();
		$data = $postVars['data'] ?? date('Y-m-d');
		$labFiltro = (int)($postVars['laboratorio_id'] ?? 0);

		AgendaHelper::gerarAulasDia($id_admin, $data);

		$where = 'agenda_aulas.id_admin = '.(int)$id_admin.' AND agenda_aulas.data_aula = "'.$data.'"';
		if ($labFiltro > 0) {
			$where .= ' AND agenda_aulas.laboratorio_id = '.$labFiltro;
		}

		$innerJoin = '
			INNER JOIN usuarios ON usuarios.id = agenda_aulas.id_aluno
			INNER JOIN trilhas ON trilhas.id = agenda_aulas.id_trilha
			INNER JOIN horarios ON horarios.id = agenda_aulas.id_horario
			LEFT JOIN laboratorios ON laboratorios.id = agenda_aulas.laboratorio_id
			LEFT JOIN presencas ON presencas.agenda_aula_id = agenda_aulas.id
		';

		$fields = 'agenda_aulas.id, usuarios.nome as aluno, trilhas.nome as curso,
			horarios.inicio, horarios.final, laboratorios.nome as lab,
			presencas.status as presenca_status, presencas.observacao';

		$results = EntityAulas::getAulas($where, 'horarios.inicio ASC, usuarios.nome ASC', null, $fields, $innerJoin);

		$agora = date('H:i:s');
		$rows = '';
		$totalLinhas = 0;
		while ($row = $results->fetch(PDO::FETCH_ASSOC)) {
			$totalLinhas++;
			$aulaId = (int)$row['id'];
			$status = trim((string)($row['presenca_status'] ?? ''));
			$inicio = (string)($row['inicio'] ?? '');
			$horarioExib = DiarioWhatsappHelper::horarioBr($inicio, $row['final'] ?? '');
			$rowClass = '';
			if ($status === '' || $status === 'aguardando') {
				if ($inicio !== '' && $inicio <= $agora) {
					$rowClass = ' class="diario-row-pendente"';
				}
			}
			$rows .= '<tr'.$rowClass.'>
				<td>'.htmlspecialchars($row['aluno']).'</td>
				<td>'.htmlspecialchars($row['curso']).'</td>
				<td>'.htmlspecialchars($row['lab'] ?? '—').'</td>
				<td>'.htmlspecialchars($horarioExib).'</td>
				<td>
					<select name="presenca['.$aulaId.']" class="form-select form-select-sm">'
					.self::statusOptionsHtml($status).
					'</select>
				</td>
				<td><input type="text" name="obs['.$aulaId.']" class="form-control form-control-sm" value="'.htmlspecialchars($row['observacao'] ?? '').'" placeholder="Obs."></td>
			</tr>';
		}

		if ($rows === '') {
			$rows = '<tr><td colspan="6" class="text-center text-muted py-4">Nenhuma aula para esta data. Verifique se há planos semanais cadastrados.</td></tr>';
		}

		$table = '<form id="formDiario">
			<input type="hidden" name="data" value="'.$data.'">
			<div class="table-responsive"><table class="table table-striped">
			<thead><tr><th>Aluno</th><th>Curso</th><th>Lab</th><th>Horário</th><th>Presença</th><th>Obs.</th></tr></thead>
			<tbody>'.$rows.'</tbody></table></div>
			<div class="text-end mt-3"><button type="submit" class="btn btn-primary">Salvar diário</button></div>
		</form>';

		$labs = '<option value="0">Todos os laboratórios</option>';
		$resLabs = EntityLabs::getLabs('id_admin = '.(int)$id_admin.' AND ativo = 1', 'nome ASC');
		while ($lab = $resLabs->fetchObject(EntityLabs::class)) {
			$sel = ($labFiltro === (int)$lab->id) ? 'selected' : '';
			$labs .= '<option '.$sel.' value="'.$lab->id.'">'.htmlspecialchars($lab->nome).'</option>';
		}

		$slugs = ModuleGateHelper::getSlugsEscola($id_admin);
		$whatsappPlano = in_array('whatsapp', $slugs, true);
		$waStatus = $whatsappPlano ? WhatsappEscolaService::status($id_admin) : null;
		$mensagens = DiarioWhatsappHelper::getMensagens($id_admin);
		$horariosLembrete = DiarioWhatsappHelper::listarHorariosDia($id_admin, $data, $labFiltro);

		return json_encode([
			'table'               => $table,
			'labs_options'        => $labs,
			'data'                => $data,
			'data_br'             => DiarioWhatsappHelper::dataBr($data),
			'hoje'                => date('Y-m-d'),
			'total'               => $totalLinhas,
			'horarios_lembrete'   => $horariosLembrete,
			'whatsapp_plano'      => $whatsappPlano,
			'whatsapp_conectado'  => !empty($waStatus['conectado']),
			'whatsapp_motivo'     => !$whatsappPlano
				? 'Módulo WhatsApp não está no plano da escola.'
				: (empty($waStatus['conectado']) ? 'WhatsApp não conectado.' : ''),
			'mensagem_lembrete'   => $mensagens['lembrete'],
			'mensagem_faltas'     => $mensagens['faltas'],
		], JSON_UNESCAPED_UNICODE);
	}

	public static function salvar($request) {
		$id_admin = parent::getIdAdminInt();
		$usuarioId = (int)parent::getIdAdmin()['usuario']['id'];
		$postVars = $request->getPostVars();
		$presencas = $postVars['presenca'] ?? [];
		$observacoes = $postVars['obs'] ?? [];

		if (!is_array($presencas) || empty($presencas)) {
			return json_encode(['erro' => 'Nenhuma presença para salvar.']);
		}

		foreach ($presencas as $aulaId => $status) {
			$aulaId = (int)$aulaId;
			if (!TenantHelper::pertence('agenda_aulas', $aulaId, $id_admin)) {
				continue;
			}

			$aula = EntityAulas::getById($aulaId, $id_admin);
			if (!$aula) {
				continue;
			}

			$ob = new EntityPresencas;
			$ob->id_admin = $id_admin;
			$ob->agenda_aula_id = $aulaId;
			$ob->id_aluno = (int)$aula->id_aluno;
			$ob->status = DiarioWhatsappHelper::normalizarStatus((string)$status);
			$ob->observacao = trim($observacoes[$aulaId] ?? '');
			$ob->registrado_por = $usuarioId;
			$ob->salvar();
		}

		return json_encode(['sucesso' => true]);
	}

	public static function whatsapp($request) {
		$idAdmin = parent::getIdAdminInt();
		$usuarioId = (int)parent::getIdAdmin()['usuario']['id'];
		$postVars = $request->getPostVars();
		$acao = (string)($postVars['acao'] ?? '');

		switch ($acao) {
			case 'salvar_mensagens':
				return self::waSalvarMensagens($idAdmin, $postVars);
			case 'preview_lembrete':
				return self::waPreviewLembrete($idAdmin, $postVars);
			case 'preview_faltas':
				return self::waPreviewFaltas($idAdmin, $postVars);
			case 'enviar_lembrete':
				return self::waEnviarLembrete($idAdmin, $usuarioId, $postVars);
			case 'enviar_faltas':
				return self::waEnviarFaltas($idAdmin, $usuarioId, $postVars);
			case 'processar_fila':
				return self::waProcessarFila($idAdmin);
			default:
				return json_encode(['success' => false, 'message' => 'Ação inválida.']);
		}
	}

	private static function waSalvarMensagens(int $idAdmin, array $postVars): string {
		if (!EscolaIntegracoes::temColunasDiarioWhatsapp()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute database/diario_whatsapp_mensagens.sql no banco.',
			]);
		}
		$lembrete = trim((string)($postVars['mensagem_lembrete'] ?? ''));
		$faltas = trim((string)($postVars['mensagem_faltas'] ?? ''));
		if ($lembrete === '' || $faltas === '') {
			return json_encode(['success' => false, 'message' => 'Preencha as duas mensagens.']);
		}
		if (!DiarioWhatsappHelper::salvarMensagens($idAdmin, $lembrete, $faltas)) {
			return json_encode(['success' => false, 'message' => 'Não foi possível salvar as mensagens.']);
		}
		return json_encode(['success' => true, 'message' => 'Mensagens salvas.']);
	}

	private static function waPreviewLembrete(int $idAdmin, array $postVars): string {
		$data = self::normalizarData($postVars['data'] ?? date('Y-m-d'));
		$labId = (int)($postVars['laboratorio_id'] ?? 0);
		$horarioId = (int)($postVars['id_horario'] ?? 0);
		if ($horarioId <= 0) {
			return json_encode(['success' => false, 'message' => 'Selecione o horário da turma.']);
		}
		$dest = DiarioWhatsappHelper::resolverLembreteHorario($idAdmin, $data, $horarioId, $labId);
		$horarios = DiarioWhatsappHelper::listarHorariosDia($idAdmin, $data, $labId);
		$horarioLabel = '';
		foreach ($horarios as $h) {
			if ((int)($h['id'] ?? 0) === $horarioId) {
				$horarioLabel = (string)($h['label'] ?? '');
				break;
			}
		}
		return json_encode([
			'success'       => true,
			'total'         => count($dest),
			'horario_label' => $horarioLabel,
			'amostra'       => array_slice(array_map(static function ($d) {
				return $d['nome'].' · '.$d['curso'];
			}, $dest), 0, 8),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function waPreviewFaltas(int $idAdmin, array $postVars): string {
		$data = self::normalizarData($postVars['data'] ?? date('Y-m-d'));
		$labId = (int)($postVars['laboratorio_id'] ?? 0);
		$dest = DiarioWhatsappHelper::resolverFaltasDia($idAdmin, $data, $labId);
		return json_encode([
			'success' => true,
			'total'   => count($dest),
			'data_br' => DiarioWhatsappHelper::dataBr($data),
			'amostra' => array_slice(array_map(static function ($d) {
				return $d['nome'].' · '.$d['curso'];
			}, $dest), 0, 8),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function waEnviarLembrete(int $idAdmin, int $usuarioId, array $postVars): string {
		$data = self::normalizarData($postVars['data'] ?? date('Y-m-d'));
		$labId = (int)($postVars['laboratorio_id'] ?? 0);
		$horarioId = (int)($postVars['id_horario'] ?? 0);
		if ($horarioId <= 0) {
			return json_encode(['ok' => false, 'success' => false, 'message' => 'Selecione o horário da turma.']);
		}
		$msg = trim((string)($postVars['mensagem_lembrete'] ?? ''));
		if ($msg === '') {
			$msg = DiarioWhatsappHelper::getMensagens($idAdmin)['lembrete'];
		}
		$dest = DiarioWhatsappHelper::resolverLembreteHorario($idAdmin, $data, $horarioId, $labId);
		$horarios = DiarioWhatsappHelper::listarHorariosDia($idAdmin, $data, $labId);
		$horarioLabel = '';
		foreach ($horarios as $h) {
			if ((int)($h['id'] ?? 0) === $horarioId) {
				$horarioLabel = (string)($h['label'] ?? '');
				break;
			}
		}
		$titulo = 'Diário — Lembrete ('.DiarioWhatsappHelper::dataBr($data);
		if ($horarioLabel !== '') {
			$titulo .= ' · '.$horarioLabel;
		}
		$titulo .= ')';
		$res = DiarioWhatsappHelper::dispararCampanha(
			$idAdmin,
			$titulo,
			$msg,
			$dest,
			$usuarioId,
			'lembrete',
			$data
		);
		return json_encode($res, JSON_UNESCAPED_UNICODE);
	}

	private static function waEnviarFaltas(int $idAdmin, int $usuarioId, array $postVars): string {
		$data = self::normalizarData($postVars['data'] ?? date('Y-m-d'));
		$labId = (int)($postVars['laboratorio_id'] ?? 0);
		$msg = trim((string)($postVars['mensagem_faltas'] ?? ''));
		if ($msg === '') {
			$msg = DiarioWhatsappHelper::getMensagens($idAdmin)['faltas'];
		}
		$dest = DiarioWhatsappHelper::resolverFaltasDia($idAdmin, $data, $labId);
		if (empty($dest)) {
			return json_encode([
				'success' => false,
				'message' => 'Nenhuma falta registrada em '.DiarioWhatsappHelper::dataBr($data).'. Salve o diário marcando "Falta" antes de enviar.',
			]);
		}
		$res = DiarioWhatsappHelper::dispararCampanha(
			$idAdmin,
			'Diário — Aviso de faltas ('.DiarioWhatsappHelper::dataBr($data).')',
			$msg,
			$dest,
			$usuarioId,
			'faltas',
			$data
		);
		return json_encode($res, JSON_UNESCAPED_UNICODE);
	}

	private static function waProcessarFila(int $idAdmin): string {
		$stats = CampanhaWorker::processar($idAdmin, 1, false);
		return json_encode(['success' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
	}

	private static function normalizarData(string $data): string {
		$data = trim($data);
		if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $data, $m)) {
			return $m[3].'-'.$m[2].'-'.$m[1];
		}
		if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $data)) {
			return $data;
		}
		return date('Y-m-d');
	}
}
