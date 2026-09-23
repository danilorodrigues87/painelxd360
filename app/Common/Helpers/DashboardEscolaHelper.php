<?php

namespace App\Common\Helpers;

use App\Model\Db\Database;
use App\Model\Entity\AgendaAulas;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\FinanceiroAcordo;
use App\Model\Entity\SaasFatura;
use App\Model\Entity\WhatsappAtendente;
use App\Model\Entity\WhatsappConversa;
use App\Model\Entity\Estoque\Stq_Produtos;
use App\Model\Entity\Estoque\Stq_Vendas;
use App\Common\Helpers\NumeroHelper;

/**
 * KPIs extras do dashboard da escola (módulos novos).
 * Falha silenciosa se tabela/módulo ausente.
 */
class DashboardEscolaHelper {

	public const ESTOQUE_LIMIAR = 3;

	/**
	 * @param string[] $acesso Labels efetivos do usuário
	 * @return array<string,mixed>
	 */
	public static function kpis(int $idAdmin, string $nivel, array $acesso, int $usuarioId): array {
		$idAdmin = (int)$idAdmin;
		$tem = static function (string $label) use ($acesso): bool {
			return in_array($label, $acesso, true);
		};

		$out = [
			'visible_hoje'       => 'd-none',
			'visible_agenda'     => 'd-none',
			'visible_whatsapp'   => 'd-none',
			'visible_pdv'        => 'd-none',
			'visible_estoque'    => 'd-none',
			'visible_ead'        => 'd-none',
			'visible_assinatura' => 'd-none',
			'visible_acordos'    => 'd-none',
			'agenda_hoje'        => '0',
			'wa_fila'            => '0',
			'wa_nao_lidas'       => '0',
			'wa_abertas'         => '0',
			'pdv_hoje_qtd'       => '0',
			'pdv_hoje_total'     => '0,00',
			'estoque_baixo'      => '0',
			'ead_concluidos'     => '0',
			'ead_andamento'      => '0',
			'ead_nao_iniciados'  => '0',
			'ead_alunos'         => '0',
			'assinatura_label'   => '—',
			'assinatura_sub'     => '',
			'assinatura_class'   => 'text-secondary',
			'acordos_ativos'     => '0',
		];

		$showHoje = false;

		if ($tem('Agendamentos') || $tem('Laboratórios') || $tem('Diário')) {
			$n = self::aulasHoje($idAdmin);
			if ($n !== null) {
				$out['visible_agenda'] = '';
				$out['agenda_hoje'] = (string)$n;
				$showHoje = true;
			}
		}

		if ($tem('WhatsApp')) {
			$wa = self::whatsapp($idAdmin, $usuarioId, $nivel);
			if ($wa !== null) {
				$out['visible_whatsapp'] = '';
				$out['wa_fila'] = (string)$wa['fila'];
				$out['wa_nao_lidas'] = (string)$wa['nao_lidas'];
				$out['wa_abertas'] = (string)$wa['abertas'];
				$showHoje = true;
			}
		}

		if ($tem('PDV')) {
			$pdv = self::pdvHoje($idAdmin);
			if ($pdv !== null) {
				$out['visible_pdv'] = '';
				$out['pdv_hoje_qtd'] = (string)$pdv['qtd'];
				$out['pdv_hoje_total'] = NumeroHelper::moedaBr($pdv['total']);
				$showHoje = true;
			}
		}

		if ($tem('Estoque')) {
			$baixo = self::estoqueBaixo($idAdmin);
			if ($baixo !== null) {
				$out['visible_estoque'] = '';
				$out['estoque_baixo'] = (string)$baixo;
				$showHoje = true;
			}
		}

		$out['visible_hoje'] = $showHoje ? '' : 'd-none';

		if ($tem('Cursos Online')) {
			$ead = self::eadTotais($idAdmin);
			if ($ead !== null) {
				$out['visible_ead'] = '';
				$out['ead_concluidos'] = (string)$ead['concluidos'];
				$out['ead_andamento'] = (string)$ead['em_andamento'];
				$out['ead_nao_iniciados'] = (string)$ead['nao_iniciados'];
				$out['ead_alunos'] = (string)$ead['alunos_unicos'];
			}
		}

		if ($nivel === 'Diretor') {
			$ass = self::assinatura($idAdmin);
			if ($ass !== null) {
				$out['visible_assinatura'] = '';
				$out['assinatura_label'] = $ass['label'];
				$out['assinatura_sub'] = $ass['sub'];
				$out['assinatura_class'] = $ass['class'];
			}
		}

		if ($tem('Carnês') || $tem('Entrada') || in_array($nivel, ['Diretor', 'Financeiro'], true)) {
			$ac = self::acordosAtivos($idAdmin);
			if ($ac !== null) {
				$out['visible_acordos'] = '';
				$out['acordos_ativos'] = (string)$ac;
			}
		}

		return $out;
	}

	private static function tabelaExiste(string $table): bool {
		static $cache = [];
		$table = preg_replace('/[^a-z0-9_]/i', '', $table) ?: '';
		if ($table === '') {
			return false;
		}
		if (array_key_exists($table, $cache)) {
			return $cache[$table];
		}
		try {
			$row = (new Database())->execute("SHOW TABLES LIKE '{$table}'")->fetch(\PDO::FETCH_NUM);
			$cache[$table] = !empty($row);
		} catch (\Throwable $e) {
			$cache[$table] = false;
		}
		return $cache[$table];
	}

	private static function aulasHoje(int $idAdmin): ?int {
		if (!self::tabelaExiste('agenda_aulas')) {
			return null;
		}
		try {
			$row = AgendaAulas::getAulas(
				'id_admin = '.(int)$idAdmin.' AND data_aula = CURDATE()',
				null,
				null,
				'COUNT(*) AS qtd'
			)->fetchObject();
			return (int)($row->qtd ?? 0);
		} catch (\Throwable $e) {
			return null;
		}
	}

	/** @return array{fila:int,nao_lidas:int,abertas:int}|null */
	private static function whatsapp(int $idAdmin, int $usuarioId, string $nivel): ?array {
		if (!WhatsappConversa::tabelaExiste()) {
			return null;
		}
		try {
			$setores = WhatsappAtendente::tabelaExiste()
				? WhatsappAtendente::setoresDoUsuario($idAdmin, $usuarioId)
				: [];
			$ind = WhatsappConversa::indicadores($idAdmin, $usuarioId, $nivel, $setores);
			return [
				'fila' => (int)($ind['fila'] ?? 0),
				'nao_lidas' => (int)($ind['nao_lidas'] ?? 0),
				'abertas' => (int)($ind['abertas'] ?? 0),
			];
		} catch (\Throwable $e) {
			return null;
		}
	}

	/** @return array{qtd:int,total:float}|null */
	private static function pdvHoje(int $idAdmin): ?array {
		if (!self::tabelaExiste('stq_vendas')) {
			return null;
		}
		try {
			$row = Stq_Vendas::getAll(
				'id_admin = '.(int)$idAdmin.' AND DATE(created_at) = CURDATE()',
				null,
				null,
				'COUNT(*) AS qtd, COALESCE(SUM(total),0) AS total'
			)->fetch(\PDO::FETCH_ASSOC);
			return [
				'qtd' => (int)($row['qtd'] ?? 0),
				'total' => (float)($row['total'] ?? 0),
			];
		} catch (\Throwable $e) {
			return null;
		}
	}

	private static function estoqueBaixo(int $idAdmin): ?int {
		if (!self::tabelaExiste('stq_produtos')) {
			return null;
		}
		try {
			$row = Stq_Produtos::getAll(
				'id_admin = '.(int)$idAdmin.' AND status = 1 AND quantidade <= '.(int)self::ESTOQUE_LIMIAR,
				null,
				null,
				'COUNT(*) AS qtd'
			)->fetch(\PDO::FETCH_ASSOC);
			return (int)($row['qtd'] ?? 0);
		} catch (\Throwable $e) {
			return null;
		}
	}

	/** @return array{concluidos:int,em_andamento:int,nao_iniciados:int,alunos_unicos:int}|null */
	private static function eadTotais(int $idAdmin): ?array {
		if (!LmsHelper::tabelasExistem()) {
			return null;
		}
		try {
			$res = LmsAdminProgressoHelper::resumoTurma($idAdmin, []);
			if (empty($res['ok'])) {
				return null;
			}
			$t = $res['totais'] ?? [];
			return [
				'concluidos' => (int)($t['concluidos'] ?? 0),
				'em_andamento' => (int)($t['em_andamento'] ?? 0),
				'nao_iniciados' => (int)($t['nao_iniciados'] ?? 0),
				'alunos_unicos' => (int)($t['alunos_unicos'] ?? 0),
			];
		} catch (\Throwable $e) {
			return null;
		}
	}

	/** @return array{label:string,sub:string,class:string}|null */
	private static function assinatura(int $idAdmin): ?array {
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola instanceof ClientesAssinantes) {
			return null;
		}

		if (SaasAssinaturaService::emTrialAtivo($escola)) {
			$ate = ClientesAssinantes::temColunaTrialAte() ? (string)($escola->trial_ate ?? '') : '';
			return [
				'label' => 'Trial',
				'sub' => $ate !== '' ? ('até '.$ate) : 'período de teste',
				'class' => 'text-info',
			];
		}

		if (!$escola->isAtiva() || (string)($escola->assinatura_status ?? '') === 'suspensa') {
			return [
				'label' => 'Suspensa',
				'sub' => 'Regularize o pagamento',
				'class' => 'text-danger',
			];
		}

		$sub = 'Ativa';
		if (SaasFatura::tabelaExiste()) {
			try {
				$aberta = SaasFatura::get(
					'id_admin = '.(int)$idAdmin.' AND status IN ("aberta","vencida")',
					'vencimento ASC',
					'1'
				)->fetchObject(SaasFatura::class);
				if ($aberta instanceof SaasFatura) {
					$sub = $aberta->status === 'vencida'
						? ('Fatura vencida · '.$aberta->competencia)
						: ('Fatura aberta · '.$aberta->competencia);
				}
			} catch (\Throwable $e) {
				// ignore
			}
		}

		return [
			'label' => 'Ativa',
			'sub' => $sub,
			'class' => 'text-success',
		];
	}

	private static function acordosAtivos(int $idAdmin): ?int {
		if (!FinanceiroAcordo::tabelasExistem()) {
			return null;
		}
		try {
			$row = (new Database('financeiro_acordos'))->select(
				'id_admin = '.(int)$idAdmin.' AND status = "ativo"',
				null,
				null,
				'COUNT(*) AS qtd'
			)->fetch(\PDO::FETCH_ASSOC);
			return (int)($row['qtd'] ?? 0);
		} catch (\Throwable $e) {
			return null;
		}
	}
}
