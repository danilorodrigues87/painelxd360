<?php

namespace App\Common\Helpers;

use App\Model\Entity\Caixa;
use App\Model\Entity\Matriculas;
use App\Model\Entity\User;
use App\Model\Entity\CrmLeads;
use App\Model\Entity\AgendaAulas;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\WhatsappConversa;
use App\Model\Db\Database;
use PDO;

/**
 * KPIs / listas read-only para o Assistente Telegram (sem sessão web).
 */
class AgentAnalyticsHelper {

	public static function resumo(int $idAdmin): array {
		$idAdmin = (int)$idAdmin;
		$matriculasAtivas = (int)Matriculas::getMatriculas(
			'id_admin = '.$idAdmin.' AND '.MatriculaStatusHelper::sqlAtiva(''),
			null, null, 'COUNT(*) as qtd'
		)->fetchObject()->qtd;

		$alunosCadastrados = (int)User::getUser(
			'id_admin = '.$idAdmin.' AND NIVEL = "Cliente"',
			null, null, 'COUNT(*) as qtd'
		)->fetchObject()->qtd;

		$recebidoHoje = (float)(Caixa::getCaixa(
			'id_admin = "'.$idAdmin.'" AND tipo_transacao = "Entrada"'
			.' AND '.FinanceiroAlunoHelper::sqlTituloPago('status')
			.' AND DATE(data_pagamento) = CURDATE()'
			.' AND '.MatriculaStatusHelper::sqlExcluirNaoReceita('tipo_pagamento'),
			null, null, 'SUM(valor_pago) as recebe'
		)->fetchObject()->recebe ?: 0);

		$receberSemana = (float)(Caixa::getCaixa(
			'id_admin = "'.$idAdmin.'" AND tipo_transacao = "Entrada"'
			.' AND WEEK(CURRENT_TIMESTAMP) = WEEK(vencimento) AND YEAR(CURRENT_DATE) = YEAR(vencimento)'
			.' AND '.FinanceiroAlunoHelper::sqlTituloAberto('status'),
			null, null, 'SUM(valor) as recebe'
		)->fetchObject()->recebe ?: 0);

		$inadMes = self::inadimplentesTotais($idAdmin, 'mes');
		$agenda = self::agendaHoje($idAdmin);
		$crm = self::crmCards($idAdmin);
		$wa = self::whatsapp($idAdmin);

		return [
			'id_admin' => $idAdmin,
			'data' => date('Y-m-d'),
			'matriculas_ativas' => $matriculasAtivas,
			'alunos_cadastrados' => $alunosCadastrados,
			'recebido_hoje' => round($recebidoHoje, 2),
			'recebido_hoje_br' => NumeroHelper::moedaBr($recebidoHoje),
			'a_receber_semana' => round($receberSemana, 2),
			'a_receber_semana_br' => NumeroHelper::moedaBr($receberSemana),
			'inadimplentes_mes' => $inadMes,
			'agenda_hoje' => [
				'qtd' => $agenda['qtd'],
			],
			'crm' => $crm,
			'whatsapp' => $wa,
		];
	}

	public static function agendaHoje(int $idAdmin, int $limit = 100): array {
		$idAdmin = (int)$idAdmin;
		$limit = max(1, min(200, $limit));
		$qtd = 0;
		$itens = [];

		try {
			$row = (new Database())->execute("SHOW TABLES LIKE 'agenda_aulas'")->fetch(PDO::FETCH_NUM);
			if (empty($row)) {
				return ['qtd' => 0, 'itens' => [], 'disponivel' => false];
			}
			$qtd = (int)(AgendaAulas::getAulas(
				'id_admin = '.$idAdmin.' AND data_aula = CURDATE()',
				null, null, 'COUNT(*) AS qtd'
			)->fetchObject()->qtd ?? 0);

			$join = 'LEFT JOIN usuarios u ON u.id = agenda_aulas.id_aluno';
			$st = AgendaAulas::getAulas(
				'agenda_aulas.id_admin = '.$idAdmin.' AND agenda_aulas.data_aula = CURDATE()',
				'agenda_aulas.id ASC',
				(string)$limit,
				'agenda_aulas.id, agenda_aulas.id_aluno, agenda_aulas.id_trilha, agenda_aulas.status, u.nome AS aluno_nome',
				$join
			);
			while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
				$itens[] = [
					'id' => (int)$r['id'],
					'id_aluno' => (int)($r['id_aluno'] ?? 0),
					'aluno' => (string)($r['aluno_nome'] ?? ''),
					'id_trilha' => (int)($r['id_trilha'] ?? 0),
					'status' => (string)($r['status'] ?? ''),
				];
			}
		} catch (\Throwable $e) {
			return ['qtd' => 0, 'itens' => [], 'disponivel' => false, 'erro' => 'agenda_indisponivel'];
		}

		return [
			'data' => date('Y-m-d'),
			'qtd' => $qtd,
			'itens' => $itens,
			'disponivel' => true,
		];
	}

	/**
	 * @param string $periodo mes|semana|hoje
	 */
	public static function inadimplentesTotais(int $idAdmin, string $periodo = 'mes'): array {
		$idAdmin = (int)$idAdmin;
		$periodo = in_array($periodo, ['mes', 'semana', 'hoje'], true) ? $periodo : 'mes';

		$filtroPeriodo = '';
		if ($periodo === 'mes') {
			$filtroPeriodo = ' AND MONTH(CURRENT_TIMESTAMP) = MONTH(vencimento) AND YEAR(CURRENT_DATE) = YEAR(vencimento)';
		} elseif ($periodo === 'semana') {
			$filtroPeriodo = ' AND WEEK(CURRENT_TIMESTAMP) = WEEK(vencimento) AND YEAR(CURRENT_DATE) = YEAR(vencimento)';
		} else {
			$filtroPeriodo = ' AND DATE(vencimento) = CURDATE()';
		}

		$where = 'id_admin = "'.$idAdmin.'" AND tipo_transacao = "Entrada"'
			.$filtroPeriodo
			.' AND DATE(vencimento) < CURRENT_DATE'
			.' AND '.FinanceiroAlunoHelper::sqlTituloAberto('status');

		$total = (float)(Caixa::getCaixa($where, null, null, 'SUM(valor) as recebe')->fetchObject()->recebe ?: 0);
		$qtd = (int)(Caixa::getCaixa($where, null, null, 'COUNT(*) as qtd')->fetchObject()->qtd ?: 0);

		return [
			'periodo' => $periodo,
			'qtd_titulos' => $qtd,
			'total' => round($total, 2),
			'total_br' => NumeroHelper::moedaBr($total),
		];
	}

	public static function inadimplentesLista(int $idAdmin, string $periodo = 'mes', int $limit = 50): array {
		$idAdmin = (int)$idAdmin;
		$limit = max(1, min(200, $limit));
		$totais = self::inadimplentesTotais($idAdmin, $periodo);

		$filtroPeriodo = '';
		if ($periodo === 'mes') {
			$filtroPeriodo = ' AND MONTH(CURRENT_TIMESTAMP) = MONTH(vencimento) AND YEAR(CURRENT_DATE) = YEAR(vencimento)';
		} elseif ($periodo === 'semana') {
			$filtroPeriodo = ' AND WEEK(CURRENT_TIMESTAMP) = WEEK(vencimento) AND YEAR(CURRENT_DATE) = YEAR(vencimento)';
		} else {
			$filtroPeriodo = ' AND DATE(vencimento) = CURDATE()';
		}

		$where = 'id_admin = "'.$idAdmin.'" AND tipo_transacao = "Entrada"'
			.$filtroPeriodo
			.' AND DATE(vencimento) < CURRENT_DATE'
			.' AND '.FinanceiroAlunoHelper::sqlTituloAberto('status');

		$itens = [];
		$st = Caixa::getCaixa(
			$where,
			'vencimento ASC',
			(string)$limit,
			'id, descricao, valor, vencimento, id_ref'
		);
		while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
			$itens[] = [
				'id' => (int)$r['id'],
				'descricao' => (string)($r['descricao'] ?? ''),
				'valor' => round((float)($r['valor'] ?? 0), 2),
				'valor_br' => NumeroHelper::moedaBr((float)($r['valor'] ?? 0)),
				'vencimento' => (string)($r['vencimento'] ?? ''),
				'id_ref' => (int)($r['id_ref'] ?? 0),
			];
		}

		return array_merge($totais, ['itens' => $itens]);
	}

	public static function aReceber(int $idAdmin, string $periodo = 'semana'): array {
		$idAdmin = (int)$idAdmin;
		$periodo = in_array($periodo, ['mes', 'semana', 'hoje'], true) ? $periodo : 'semana';

		$filtroPeriodo = '';
		if ($periodo === 'mes') {
			$filtroPeriodo = ' AND MONTH(CURRENT_TIMESTAMP) = MONTH(vencimento) AND YEAR(CURRENT_DATE) = YEAR(vencimento)';
		} elseif ($periodo === 'semana') {
			$filtroPeriodo = ' AND WEEK(CURRENT_TIMESTAMP) = WEEK(vencimento) AND YEAR(CURRENT_DATE) = YEAR(vencimento)';
		} else {
			$filtroPeriodo = ' AND DATE(vencimento) = CURDATE()';
		}

		$where = 'id_admin = "'.$idAdmin.'" AND tipo_transacao = "Entrada"'
			.$filtroPeriodo
			.' AND '.FinanceiroAlunoHelper::sqlTituloAberto('status');

		$total = (float)(Caixa::getCaixa($where, null, null, 'SUM(valor) as recebe')->fetchObject()->recebe ?: 0);
		$qtd = (int)(Caixa::getCaixa($where, null, null, 'COUNT(*) as qtd')->fetchObject()->qtd ?: 0);

		return [
			'periodo' => $periodo,
			'qtd_titulos' => $qtd,
			'total' => round($total, 2),
			'total_br' => NumeroHelper::moedaBr($total),
		];
	}

	public static function crmCards(int $idAdmin): array {
		$idAdmin = (int)$idAdmin;
		$labels = [
			'novo' => 'Novo',
			'em_atendimento' => 'Em atendimento',
			'matriculado' => 'Matriculado',
			'perdido' => 'Perdido',
		];
		$porStatus = [];
		$total = 0;
		foreach ($labels as $slug => $label) {
			$q = (int)CrmLeads::getLeads(
				'id_admin = '.$idAdmin.' AND status = "'.addslashes($slug).'"',
				null, null, 'COUNT(*) as qtd'
			)->fetch(PDO::FETCH_ASSOC)['qtd'];
			$porStatus[$slug] = ['slug' => $slug, 'label' => $label, 'qtd' => $q];
			$total += $q;
		}
		$matriculado = $porStatus['matriculado']['qtd'];
		$conversao = $total > 0 ? round(($matriculado / $total) * 100, 1) : 0;

		return [
			'total' => $total,
			'por_status' => array_values($porStatus),
			'conversao_pct' => $conversao,
		];
	}

	public static function crmResumo(int $idAdmin, string $de = '', string $ate = ''): array {
		$idAdmin = (int)$idAdmin;
		if ($de === '') {
			$de = date('Y-m-01');
		}
		if ($ate === '') {
			$ate = date('Y-m-d');
		}
		$de = preg_replace('/[^0-9\-]/', '', $de);
		$ate = preg_replace('/[^0-9\-]/', '', $ate);

		$whereBase = 'id_admin = '.$idAdmin
			.' AND DATE(data_cadastro) >= "'.addslashes($de).'"'
			.' AND DATE(data_cadastro) <= "'.addslashes($ate).'"';

		$porStatus = [];
		$total = 0;
		$valorTotal = 0.0;
		$st = CrmLeads::getLeads($whereBase, null, null, 'status, COUNT(*) AS qtd, COALESCE(SUM(valor_estimado),0) AS valor', null, 'status');
		while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
			$status = (string)($row['status'] ?? 'novo');
			$q = (int)($row['qtd'] ?? 0);
			$v = (float)($row['valor'] ?? 0);
			$porStatus[] = [
				'slug' => $status,
				'qtd' => $q,
				'valor' => round($v, 2),
				'valor_br' => NumeroHelper::moedaBr($v),
			];
			$total += $q;
			$valorTotal += $v;
		}

		$matriculados = 0;
		foreach ($porStatus as $p) {
			if ($p['slug'] === 'matriculado') {
				$matriculados = $p['qtd'];
			}
		}

		return [
			'de' => $de,
			'ate' => $ate,
			'total_leads' => $total,
			'valor_estimado_total' => round($valorTotal, 2),
			'valor_estimado_total_br' => NumeroHelper::moedaBr($valorTotal),
			'conversao_pct' => $total > 0 ? round(($matriculados / $total) * 100, 1) : 0,
			'por_status' => $porStatus,
		];
	}

	public static function matriculasResumo(int $idAdmin): array {
		$idAdmin = (int)$idAdmin;
		$ativas = (int)Matriculas::getMatriculas(
			'id_admin = '.$idAdmin.' AND '.MatriculaStatusHelper::sqlAtiva(''),
			null, null, 'COUNT(*) as qtd'
		)->fetchObject()->qtd;

		$novasMes = 0;
		try {
			$col = Matriculas::campoDataMatricula('matriculas');
			$novasMes = (int)Matriculas::getMatriculas(
				'id_admin = '.$idAdmin.' AND MONTH('.$col.') = MONTH(CURDATE()) AND YEAR('.$col.') = YEAR(CURDATE())',
				null, null, 'COUNT(*) as qtd'
			)->fetchObject()->qtd;
		} catch (\Throwable $e) {
			$novasMes = 0;
		}

		return [
			'ativas' => $ativas,
			'novas_mes' => $novasMes,
		];
	}

	public static function whatsapp(int $idAdmin): array {
		$idAdmin = (int)$idAdmin;
		if (!WhatsappConversa::tabelaExiste()) {
			return ['disponivel' => false, 'fila' => 0, 'nao_lidas' => 0, 'abertas' => 0];
		}
		try {
			$ind = WhatsappConversa::indicadores($idAdmin, 0, 'Diretor', []);
			return [
				'disponivel' => true,
				'fila' => (int)($ind['fila'] ?? 0),
				'nao_lidas' => (int)($ind['nao_lidas'] ?? 0),
				'abertas' => (int)($ind['abertas'] ?? 0),
			];
		} catch (\Throwable $e) {
			return ['disponivel' => false, 'fila' => 0, 'nao_lidas' => 0, 'abertas' => 0];
		}
	}

	public static function listarEscolas(): array {
		$out = [];
		$st = ClientesAssinantes::getEscolas(null, 'nome ASC', null, 'id, nome, ativo, assinatura_status, email');
		while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
			$id = (int)($r['id'] ?? 0);
			$out[] = [
				'id_admin' => $id,
				'nome' => (string)($r['nome'] ?? ''),
				'ativo' => ClientesAssinantes::isAtivaValor($r['ativo'] ?? 1),
				'assinatura_status' => (string)($r['assinatura_status'] ?? ''),
				'email' => (string)($r['email'] ?? ''),
				'assistente_ia' => in_array('assistente_ia', ModuleGateHelper::getSlugsEscola($id), true),
			];
		}
		return $out;
	}
}
