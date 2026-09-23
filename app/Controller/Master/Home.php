<?php

namespace App\Controller\Master;

use App\Utils\View;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\PlanosAssinatura;
use App\Model\Entity\SaasFatura;
use App\Common\Helpers\SaasAssinaturaService;

class Home extends Page {

	public static function index($request) {
		$escolas = self::contarEscolas();
		$saasOk = false;
		$dash = [
			'escolas_ativas' => $escolas['ativas'],
			'escolas_trial' => 0,
			'escolas_suspensas' => $escolas['inativas'],
			'faturas_abertas' => 0,
			'faturas_vencidas' => 0,
			'receita_mes_br' => '0,00',
			'competencia' => date('Y-m'),
		];

		try {
			$saasOk = ClientesAssinantes::temColunasAssinatura() && SaasFatura::tabelaExiste();
			if ($saasOk) {
				$dash = SaasAssinaturaService::dashboardStats();
			}
		} catch (\Throwable $e) {
			error_log('[Master\\Home] dashboardStats: '.$e->getMessage());
			$saasOk = false;
		}

		$planosAtivos = 0;
		try {
			if (PlanosAssinatura::tabelaExiste()) {
				$row = PlanosAssinatura::get('ativo = 1', null, null, 'COUNT(*) AS q')->fetch(\PDO::FETCH_ASSOC);
				$planosAtivos = (int)($row['q'] ?? 0);
			}
		} catch (\Throwable $e) {
			error_log('[Master\\Home] planos: '.$e->getMessage());
		}

		$content = View::render('master/modules/home/index', [
			'total' => $escolas['total'],
			'ativas' => $escolas['ativas'],
			'inativas' => $escolas['inativas'],
			'saas_ok' => $saasOk ? '' : 'd-none',
			'saas_warn' => $saasOk ? 'd-none' : '',
			'dash_ativas' => (int)($dash['escolas_ativas'] ?? 0),
			'dash_trial' => (int)($dash['escolas_trial'] ?? 0),
			'dash_suspensas' => (int)($dash['escolas_suspensas'] ?? 0),
			'dash_abertas' => (int)($dash['faturas_abertas'] ?? 0),
			'dash_vencidas' => (int)($dash['faturas_vencidas'] ?? 0),
			'dash_receita' => (string)($dash['receita_mes_br'] ?? '0,00'),
			'dash_competencia' => (string)($dash['competencia'] ?? date('Y-m')),
			'planos_ativos' => $planosAtivos,
			'lista_recentes' => self::htmlEscolasRecentes(),
			'lista_atencao' => self::htmlAtencao(),
		]);

		return parent::getPanel('Dashboard Master', $content, 'home');
	}

	private static function contarEscolas(): array {
		$total = 0;
		$ativas = 0;
		try {
			$results = ClientesAssinantes::getEscolas(null, 'nome ASC', null, 'id, ativo');
			while ($row = $results->fetch(\PDO::FETCH_ASSOC)) {
				$total++;
				if (ClientesAssinantes::isAtivaValor($row['ativo'] ?? null)) {
					$ativas++;
				}
			}
		} catch (\Throwable $e) {
			error_log('[Master\\Home] contarEscolas: '.$e->getMessage());
		}
		return [
			'total' => $total,
			'ativas' => $ativas,
			'inativas' => max(0, $total - $ativas),
		];
	}

	private static function htmlEscolasRecentes(): string {
		try {
			$rows = [];
			// Colunas reais: cidade/estado são IDs (não existe coluna "uf")
			$results = ClientesAssinantes::getEscolas(null, 'id DESC', '0,8', 'id, nome, ativo');
			while ($e = $results->fetchObject(ClientesAssinantes::class)) {
				$badge = $e->isAtiva()
					? '<span class="badge bg-success">Ativa</span>'
					: '<span class="badge bg-secondary">Inativa</span>';
				$rows[] = '<tr>'
					.'<td class="fw-semibold">'.self::esc((string)$e->nome).'</td>'
					.'<td class="small text-muted">#'.(int)$e->id.'</td>'
					.'<td>'.$badge.'</td>'
					.'</tr>';
			}
			if (!$rows) {
				return '<tr><td colspan="3" class="text-muted text-center py-3">Nenhum cliente cadastrado.</td></tr>';
			}
			return implode('', $rows);
		} catch (\Throwable $e) {
			error_log('[Master\\Home] recentes: '.$e->getMessage());
			return '<tr><td colspan="3" class="text-danger text-center py-3">Não foi possível carregar.</td></tr>';
		}
	}

	private static function htmlAtencao(): string {
		try {
			$itens = [];

			if (ClientesAssinantes::temColunasAssinatura()) {
				$limite = date('Y-m-d', strtotime('+7 days'));
				$hoje = date('Y-m-d');
				$fields = 'id, nome, ativo, assinatura_status';
				if (ClientesAssinantes::temColunaTrialAte()) {
					$fields .= ', trial_ate';
				}
				$results = ClientesAssinantes::getEscolas(null, 'id ASC', null, $fields);
				while ($e = $results->fetchObject(ClientesAssinantes::class)) {
					if (SaasAssinaturaService::trialExpirado($e)) {
						$itens[] = [
							'tipo' => 'Trial expirado',
							'nome' => (string)$e->nome,
							'detalhe' => 'trial_ate '.(($e->trial_ate ?? '') ?: '—'),
							'badge' => 'danger',
						];
					} elseif (SaasAssinaturaService::emTrialAtivo($e)) {
						$ate = trim((string)($e->trial_ate ?? ''));
						if ($ate !== '' && $ate <= $limite) {
							$itens[] = [
								'tipo' => 'Trial acabando',
								'nome' => (string)$e->nome,
								'detalhe' => 'até '.$ate,
								'badge' => $ate < $hoje ? 'danger' : 'warning',
							];
						}
					}
				}
			}

			if (SaasFatura::tabelaExiste()) {
				$stmt = SaasFatura::get(
					'status = "vencida"',
					'vencimento ASC',
					'0,8',
					'id, id_admin, competencia, valor, vencimento'
				);
				while ($f = $stmt->fetchObject(SaasFatura::class)) {
					$escola = ClientesAssinantes::getEscolaById((int)$f->id_admin);
					$nome = $escola instanceof ClientesAssinantes ? (string)$escola->nome : ('#'.$f->id_admin);
					$itens[] = [
						'tipo' => 'Fatura vencida',
						'nome' => $nome,
						'detalhe' => ($f->competencia ?? '').' · R$ '.number_format((float)$f->valor, 2, ',', '.').' · venc. '.$f->vencimento,
						'badge' => 'danger',
					];
				}
			}

			if (!$itens) {
				return '<tr><td colspan="3" class="text-muted text-center py-3">Nada urgente no momento.</td></tr>';
			}

			usort($itens, static function ($a, $b) {
				$pa = ($a['badge'] ?? '') === 'danger' ? 0 : 1;
				$pb = ($b['badge'] ?? '') === 'danger' ? 0 : 1;
				return $pa <=> $pb;
			});
			$itens = array_slice($itens, 0, 12);

			$html = [];
			foreach ($itens as $it) {
				$html[] = '<tr>'
					.'<td><span class="badge bg-'.self::esc($it['badge']).'">'.self::esc($it['tipo']).'</span></td>'
					.'<td class="fw-semibold">'.self::esc($it['nome']).'</td>'
					.'<td class="small text-muted">'.self::esc($it['detalhe']).'</td>'
					.'</tr>';
			}
			return implode('', $html);
		} catch (\Throwable $e) {
			error_log('[Master\\Home] atencao: '.$e->getMessage());
			return '<tr><td colspan="3" class="text-danger text-center py-3">Não foi possível carregar.</td></tr>';
		}
	}

	private static function esc(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
