<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\SaasAssinaturaService;
use App\Common\Helpers\SaasContratoTemplateHelper;
use App\Common\Helpers\SaasContratoVariaveisBuilder;
use App\Common\Helpers\MercadoPagoXd360Helper;
use App\Common\Helpers\DateTimeHelper;
use App\Model\Entity\SaasContratoModelo;
use App\Common\Gateways\MercadoPago\Pix;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\PlanosAssinatura;
use App\Model\Entity\SaasFatura;

class AssinaturaEscola extends Page {

	private static function assertAcessoTela($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		$nivel = $user['usuario']['nivel'] ?? '';
		$bloqueada = !empty($user['usuario']['assinatura_bloqueada']);
		if ($nivel === 'Diretor' || $bloqueada) {
			return true;
		}
		if (!$api) {
			$request->getRouter()->redirect('/painel');
		}
		return false;
	}

	private static function assertDiretor($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor') {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		return true;
	}

	public static function index($request) {
		if (!self::assertAcessoTela($request)) {
			return '';
		}
		$user = SessionUser::getUserLogedData();
		$isDiretor = (($user['usuario']['nivel'] ?? '') === 'Diretor');
		$contratoLink = '';
		if (SaasContratoModelo::tabelaExiste()) {
			$contratoLink = '<a href="'.URL.'/painel/assinatura/contrato" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">'
				.'<i class="fas fa-file-contract"></i> Ver contrato de licença</a>';
		}
		$content = View::render('admin/modules/assinatura/index', [
			'grace_dias' => (string)SaasAssinaturaService::GRACE_DIAS,
			'so_leitura' => $isDiretor ? '0' : '1',
			'contrato_link' => $contratoLink,
		]);
		return parent::getPanel('Assinatura', $content, 'Financeiro', $request);
	}

	public static function verContrato($request) {
		if (!self::assertAcessoTela($request)) {
			return '';
		}
		if (!SaasContratoModelo::tabelaExiste()) {
			return 'Contrato de licença ainda não disponível. Contate o suporte CTI.';
		}
		$idAdmin = TenantHelper::getIdAdmin();
		if ($idAdmin <= 0) {
			return 'Escola não identificada.';
		}
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola instanceof ClientesAssinantes) {
			return 'Escola não encontrada.';
		}
		$vars = SaasContratoVariaveisBuilder::montarFromEscola($escola);
		return SaasContratoTemplateHelper::render($vars);
	}

	public static function getInfo($request) {
		if (!self::assertAcessoTela($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}

		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		if ($acao === 'carregar') {
			return self::carregar($post);
		}

		if (!self::assertDiretor($request, true)) {
			return json_encode(['success' => false, 'message' => 'Apenas o Diretor pode pagar/atualizar o PIX.']);
		}

		switch ($acao) {
			case 'atualizar_pix':
				return self::atualizarPix($post);
			case 'verificar':
				return self::verificar($post);
			default:
				return json_encode(['success' => false, 'message' => 'Ação inválida.']);
		}
	}

	private static function carregar(array $post = []): string {
		$idAdmin = TenantHelper::getIdAdmin();
		if ($idAdmin <= 0) {
			return json_encode(['success' => false, 'message' => 'Escola não identificada.']);
		}

		if (!SaasFatura::tabelaExiste()) {
			return json_encode([
				'success' => true,
				'tabela_ok' => false,
				'message' => 'Cobrança de assinatura ainda não está disponível.',
				'resumo' => null,
				'faturas' => [],
			]);
		}

		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		$planoNome = null;
		$valorMensal = null;
		$valorCustom = null;
		$emTrial = false;
		$trialAte = null;
		if ($escola instanceof ClientesAssinantes) {
			$valorMensal = SaasAssinaturaService::resolverValorMensal($escola);
			if ($valorMensal <= 0) {
				$valorMensal = null;
			}
			$planId = (int)($escola->plan_id ?? 0);
			if ($planId > 0) {
				$plano = PlanosAssinatura::getById($planId);
				if ($plano instanceof PlanosAssinatura) {
					$planoNome = (string)$plano->nome;
				}
			} else {
				$planoNome = 'Personalizado';
			}
			if (ClientesAssinantes::temColunaValorMensalCustom() && (float)($escola->valor_mensal_custom ?? 0) > 0) {
				$valorCustom = round((float)$escola->valor_mensal_custom, 2);
			}
			$emTrial = SaasAssinaturaService::emTrialAtivo($escola);
			$trialAte = ClientesAssinantes::temColunaTrialAte() ? ($escola->trial_ate ?? null) : null;
		}

		$where = 'id_admin = '.(int)$idAdmin;
		$page = max(1, (int)($post['page'] ?? 1));
		$limit = 12;
		$rowCount = SaasFatura::get($where, null, null, 'COUNT(*) as q')->fetch(\PDO::FETCH_ASSOC);
		$total = (int)($rowCount['q'] ?? 0);
		$pages = max(1, (int)ceil($total / $limit));
		if ($page > $pages) {
			$page = $pages;
		}
		$offset = ($page - 1) * $limit;
		$results = SaasFatura::get($where, 'competencia DESC, id DESC', $offset.','.$limit);
		$faturas = [];
		$aberta = null;
		while ($f = $results->fetchObject(SaasFatura::class)) {
			$row = SaasAssinaturaService::formatar($f, $escola instanceof ClientesAssinantes ? $escola : null);
			$faturas[] = $row;
			if ($aberta === null && in_array($row['status'], ['aberta', 'vencida'], true)) {
				$aberta = $row;
			}
		}

		// Se a aberta não está nesta página, busca a mais recente em aberto
		if ($aberta === null) {
			$stAb = SaasFatura::get(
				$where.' AND status IN ("aberta","vencida")',
				'vencimento ASC, id ASC',
				'1'
			);
			if ($fAb = $stAb->fetchObject(SaasFatura::class)) {
				$aberta = SaasAssinaturaService::formatar($fAb, $escola instanceof ClientesAssinantes ? $escola : null);
			}
		}

		$user = SessionUser::getUserLogedData();
		$proxVenc = $escola instanceof ClientesAssinantes
			? ($escola->assinatura_proximo_vencimento ?? null)
			: null;

		$contratoPendencias = [];
		if ($escola instanceof ClientesAssinantes && SaasContratoModelo::tabelaExiste()) {
			$contratoPendencias = SaasContratoVariaveisBuilder::listarPendencias($escola);
		}

		return json_encode([
			'success'   => true,
			'tabela_ok' => true,
			'contrato_pendencias' => $contratoPendencias,
			'resumo'    => [
				'plano_nome'                   => $planoNome,
				'valor_mensal'                 => $valorMensal,
				'valor_mensal_br'              => $valorMensal !== null
					? number_format($valorMensal, 2, ',', '.')
					: null,
				'valor_custom'                 => $valorCustom,
				'dia_vencimento'               => $escola instanceof ClientesAssinantes && ClientesAssinantes::temColunasAssinatura()
					? max(1, min(28, (int)($escola->dia_vencimento_assinatura ?? 10)))
					: 10,
				'assinatura_status'            => $escola instanceof ClientesAssinantes && ClientesAssinantes::temColunasAssinatura()
					? (string)($escola->assinatura_status ?? 'ativa')
					: 'ativa',
				'assinatura_proximo_vencimento'=> $proxVenc,
				'assinatura_proximo_vencimento_br' => self::dataBrSafe($proxVenc),
				'escola_ativa'                 => $escola instanceof ClientesAssinantes ? $escola->isAtiva() : false,
				'em_trial'                     => $emTrial,
				'trial_ate'                    => $trialAte,
				'trial_ate_br'                 => self::dataBrSafe($trialAte),
				'grace_dias'                   => SaasAssinaturaService::GRACE_DIAS,
				'so_leitura'                   => (($user['usuario']['nivel'] ?? '') !== 'Diretor'),
				'bloqueada'                    => !empty($user['usuario']['assinatura_bloqueada']),
			],
			'aberta'  => $aberta,
			'faturas' => $faturas,
			'pagination' => [
				'page' => $page,
				'pages' => $pages,
				'total' => $total,
				'limit' => $limit,
			],
		], JSON_UNESCAPED_UNICODE);
	}

	private static function dataBrSafe($data): ?string {
		if ($data === null || $data === '') {
			return null;
		}
		try {
			$s = (string)$data;
			$br = DateTimeHelper::databr($s);
			if (strlen($s) > 10) {
				$br .= ' '.substr(DateTimeHelper::extrairHorario($s), 0, 5);
			}
			return $br;
		} catch (\Throwable $e) {
			return (string)$data;
		}
	}

	private static function atualizarPix(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$fat = SaasFatura::getById($id);
		if (!$fat instanceof SaasFatura || (int)$fat->id_admin !== $idAdmin) {
			return json_encode(['success' => false, 'message' => 'Fatura não encontrada.']);
		}
		if ($fat->status === 'pago') {
			return json_encode(['success' => false, 'message' => 'Fatura já está paga.']);
		}
		if (!MercadoPagoXd360Helper::configurado()) {
			return json_encode(['success' => false, 'message' => 'Pagamento PIX temporariamente indisponível. Contate o suporte.']);
		}

		$fat->mp_payment_id = null;
		$fat->pix_copia_cola = null;
		$fat->pix_qr_base64 = null;
		$ok = SaasAssinaturaService::anexarPix($fat);
		if (!$ok) {
			$err = Pix::getUltimoErro();
			return json_encode([
				'success' => false,
				'message' => $err ?: 'Não foi possível gerar o PIX. Tente novamente em instantes.',
			]);
		}

		return json_encode([
			'success' => true,
			'message' => 'PIX atualizado.',
			'fatura'  => SaasAssinaturaService::formatar($fat),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function verificar(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$fat = SaasFatura::getById($id);
		if (!$fat instanceof SaasFatura || (int)$fat->id_admin !== $idAdmin) {
			return json_encode(['success' => false, 'message' => 'Fatura não encontrada.']);
		}
		if ($fat->status === 'pago') {
			return json_encode([
				'success' => true,
				'message' => 'Fatura já está paga.',
				'fatura'  => SaasAssinaturaService::formatar($fat),
			]);
		}

		$paymentId = preg_replace('/\D/', '', (string)($fat->mp_payment_id ?? ''));
		if ($paymentId === '') {
			return json_encode(['success' => false, 'message' => 'Esta fatura ainda não tem PIX gerado.']);
		}

		$pix = MercadoPagoXd360Helper::pix();
		if (!$pix instanceof Pix) {
			return json_encode(['success' => false, 'message' => 'Consulta indisponível no momento.']);
		}

		$pagamento = $pix->consultarPagamento($paymentId);
		if (!$pagamento) {
			return json_encode(['success' => false, 'message' => 'Não foi possível consultar o pagamento.']);
		}

		if (($pagamento['status'] ?? '') === 'approved') {
			$pagoEm = date('Y-m-d H:i:s');
			if (!empty($pagamento['date_approved'])) {
				try {
					$pagoEm = (new \DateTimeImmutable((string)$pagamento['date_approved']))->format('Y-m-d H:i:s');
				} catch (\Throwable $e) {
					// keep now
				}
			}
			SaasAssinaturaService::marcarPaga($fat, $pagoEm);
			return json_encode([
				'success' => true,
				'message' => 'Pagamento confirmado! Obrigado.',
				'fatura'  => SaasAssinaturaService::formatar($fat),
			], JSON_UNESCAPED_UNICODE);
		}

		return json_encode([
			'success' => true,
			'message' => 'Ainda não identificamos o pagamento (status: '.($pagamento['status'] ?? 'pendente').').',
			'fatura'  => SaasAssinaturaService::formatar($fat),
		], JSON_UNESCAPED_UNICODE);
	}
}
