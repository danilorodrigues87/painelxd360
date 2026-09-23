<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\DateTimeHelper;
use App\Model\Entity\CrmLeads;
use App\Model\Entity\CrmFunis;

/**
 * Relatórios do CRM (somente Diretor).
 */
class CrmRelatorios extends Page {

	private static $statusLabels = [
		'novo'            => 'Novo',
		'em_atendimento'  => 'Em atendimento',
		'matriculado'     => 'Matriculado',
		'perdido'         => 'Perdido',
	];

	private static $motivosPerda = [
		'preco' => 'Preço',
		'concorrente' => 'Concorrente',
		'sem_interesse' => 'Sem interesse',
		'nao_respondeu' => 'Não respondeu',
		'outro' => 'Outro',
	];

	public static function index($request) {
		if (!self::assertDiretor($request)) {
			return '';
		}
		$content = View::render('admin/modules/crm/relatorios', []);
		return parent::getPanel('Leads', $content, 'CRM', $request);
	}

	public static function getInfo($request) {
		header('Content-Type: application/json; charset=utf-8');
		if (!self::assertDiretor($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso restrito ao Diretor.']);
		}
		$post = $request->getPostVars();
		$acao = (string)($post['acao'] ?? 'resumo');
		if ($acao === 'resumo') {
			return self::resumo($post);
		}
		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function assertDiretor($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor') {
			if (!$api) {
				$request->getRouter()->redirect('/painel/crm');
			}
			return false;
		}
		return true;
	}

	private static function resumo(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$de = trim((string)($post['de'] ?? ''));
		$ate = trim((string)($post['ate'] ?? ''));

		if ($de === '') {
			$de = date('Y-m-01');
		} else {
			$de = self::normalizarData($de);
		}
		if ($ate === '') {
			$ate = DateTimeHelper::hoje();
		} else {
			$ate = self::normalizarData($ate);
		}

		$whereBase = 'id_admin = '.(int)$idAdmin
			.' AND DATE(data_cadastro) >= "'.addslashes($de).'"'
			.' AND DATE(data_cadastro) <= "'.addslashes($ate).'"';

		$porStatus = [];
		foreach (self::$statusLabels as $slug => $label) {
			$porStatus[$slug] = ['slug' => $slug, 'label' => $label, 'qtd' => 0, 'valor' => 0.0];
		}

		$total = 0;
		$valorTotal = 0.0;
		$st = CrmLeads::getLeads($whereBase, null, null, 'status, COUNT(*) AS qtd, COALESCE(SUM(valor_estimado),0) AS valor', null, 'status');
		while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
			$status = (string)($row['status'] ?? 'novo');
			if (!isset($porStatus[$status])) {
				$porStatus[$status] = ['slug' => $status, 'label' => $status, 'qtd' => 0, 'valor' => 0.0];
			}
			$q = (int)($row['qtd'] ?? 0);
			$v = (float)($row['valor'] ?? 0);
			$porStatus[$status]['qtd'] = $q;
			$porStatus[$status]['valor'] = $v;
			$total += $q;
			$valorTotal += $v;
		}

		$matriculados = (int)($porStatus['matriculado']['qtd'] ?? 0);
		$perdidos = (int)($porStatus['perdido']['qtd'] ?? 0);
		$conversao = $total > 0 ? round(($matriculados / $total) * 100, 1) : 0.0;
		$perdaPct = $total > 0 ? round(($perdidos / $total) * 100, 1) : 0.0;

		$porFunil = [];
		$funis = [];
		$stF = CrmFunis::getFunis('id_admin = '.(int)$idAdmin, 'nome ASC');
		while ($f = $stF->fetchObject(CrmFunis::class)) {
			$funis[(int)$f->id] = (string)$f->nome;
		}
		$st2 = CrmLeads::getLeads(
			$whereBase,
			null,
			null,
			'funil_id, status, COUNT(*) AS qtd',
			null,
			'funil_id, status'
		);
		$funilAgg = [];
		while ($row = $st2->fetch(\PDO::FETCH_ASSOC)) {
			$fid = (int)($row['funil_id'] ?? 0);
			if (!isset($funilAgg[$fid])) {
				$funilAgg[$fid] = [
					'funil_id' => $fid,
					'nome' => $funis[$fid] ?? ($fid > 0 ? 'Funil #'.$fid : 'Sem funil'),
					'total' => 0,
					'por_status' => array_fill_keys(array_keys(self::$statusLabels), 0),
				];
			}
			$stSlug = (string)($row['status'] ?? 'novo');
			$q = (int)($row['qtd'] ?? 0);
			$funilAgg[$fid]['total'] += $q;
			if (!isset($funilAgg[$fid]['por_status'][$stSlug])) {
				$funilAgg[$fid]['por_status'][$stSlug] = 0;
			}
			$funilAgg[$fid]['por_status'][$stSlug] += $q;
		}
		foreach ($funilAgg as $row) {
			$mat = (int)($row['por_status']['matriculado'] ?? 0);
			$row['conversao_pct'] = $row['total'] > 0 ? round(($mat / $row['total']) * 100, 1) : 0.0;
			$porFunil[] = $row;
		}
		usort($porFunil, static function ($a, $b) {
			return $b['total'] <=> $a['total'];
		});

		$motivos = [];
		$st3 = CrmLeads::getLeads(
			$whereBase.' AND status = "perdido"',
			null,
			null,
			'motivo_perda, COUNT(*) AS qtd',
			null,
			'motivo_perda'
		);
		while ($row = $st3->fetch(\PDO::FETCH_ASSOC)) {
			$m = (string)($row['motivo_perda'] ?? '');
			$motivos[] = [
				'motivo' => $m !== '' ? (self::$motivosPerda[$m] ?? $m) : 'Não informado',
				'qtd' => (int)($row['qtd'] ?? 0),
			];
		}

		$origens = [];
		$st4 = CrmLeads::getLeads(
			$whereBase,
			null,
			null,
			'origem, COUNT(*) AS qtd',
			null,
			'origem'
		);
		while ($row = $st4->fetch(\PDO::FETCH_ASSOC)) {
			$o = trim((string)($row['origem'] ?? ''));
			$origens[] = [
				'origem' => $o !== '' ? $o : 'Não informada',
				'qtd' => (int)($row['qtd'] ?? 0),
			];
		}
		usort($origens, static function ($a, $b) {
			return $b['qtd'] <=> $a['qtd'];
		});
		$origens = array_slice($origens, 0, 15);

		return json_encode([
			'success' => true,
			'periodo' => [
				'de' => $de,
				'ate' => $ate,
				'de_br' => DateTimeHelper::databr($de),
				'ate_br' => DateTimeHelper::databr($ate),
			],
			'kpis' => [
				'total' => $total,
				'matriculados' => $matriculados,
				'perdidos' => $perdidos,
				'conversao_pct' => $conversao,
				'perda_pct' => $perdaPct,
				'valor_estimado' => round($valorTotal, 2),
				'valor_estimado_br' => number_format($valorTotal, 2, ',', '.'),
			],
			'por_status' => array_values($porStatus),
			'por_funil' => $porFunil,
			'motivos_perda' => $motivos,
			'origens' => $origens,
			'tarefas_nota' => 'Relatório de tarefas (cards por lista, tempo médio, concluídas no período) está no roadmap — a estrutura atual é Kanban por listas sem status/data de conclusão padronizados.',
		], JSON_UNESCAPED_UNICODE);
	}

	private static function normalizarData(string $data): string {
		$data = trim($data);
		if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $data)) {
			return DateTimeHelper::dataEn($data);
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
			return $data;
		}
		return DateTimeHelper::hoje();
	}
}
