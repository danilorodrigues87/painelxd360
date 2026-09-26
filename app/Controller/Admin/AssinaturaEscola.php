<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\SaasAssinaturaService;
use App\Common\Helpers\SaasContratoService;
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
		$html = SaasContratoTemplateHelper::render($vars);
		$_SESSION['contrato_leitura_inicio'] = time();
		return self::comBarraAceite($html, $escola);
	}

	public static function aceitarContrato($request): string {
		if (!self::assertDiretor($request, true)) {
			return json_encode(['success' => false, 'message' => 'Apenas o diretor pode aceitar o contrato.']);
		}
		$post = $request->getPostVars();
		$user = SessionUser::getUserLogedData();
		$uid = (int)($user['usuario']['id'] ?? 0);
		$obUser = $uid > 0 ? \App\Model\Entity\User::getUserById($uid) : null;
		$nome = $obUser instanceof \App\Model\Entity\User ? (string)$obUser->nome : (string)($user['usuario']['nome'] ?? '');
		$cpfPost = preg_replace('/\D+/', '', (string)($post['cpf'] ?? ''));
		$cpf = strlen($cpfPost) === 11
			? $cpfPost
			: ($obUser instanceof \App\Model\Entity\User ? (string)($obUser->cpf ?? '') : '');
		if ($obUser instanceof \App\Model\Entity\User && strlen($cpfPost) === 11 && preg_replace('/\D+/', '', (string)$obUser->cpf) !== $cpfPost) {
			$obUser->cpf = $cpfPost;
			$obUser->nascimento = $obUser->nascimento ?: null;
			$obUser->uf = (int)($obUser->uf ?: 0);
			$obUser->cidade = (int)($obUser->cidade ?: 0);
			try {
				$obUser->atualizaPerfil();
			} catch (\Throwable $e) {
			}
		}
		$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
		$r = SaasContratoService::aceitar(
			TenantHelper::getIdAdmin(),
			$uid,
			$nome,
			$cpf,
			$ip,
			!empty($post['leu'])
		);
		return json_encode(['success' => $r['ok'], 'message' => $r['message']], JSON_UNESCAPED_UNICODE);
	}

	private static function comBarraAceite(string $html, ClientesAssinantes $escola): string {
		$user = SessionUser::getUserLogedData();
		$diretor = (($user['usuario']['nivel'] ?? '') === 'Diretor');
		$vigentes = \App\Model\Entity\SaasContrato::vigentesDoCliente((int)$escola->id);
		$pendente = false;
		$quando = '';
		foreach ($vigentes as $c) {
			if (trim((string)($c->aceito_em ?? '')) === '') {
				$pendente = true;
			} elseif ($quando === '') {
				$quando = (string)$c->aceito_em;
			}
		}
		$aviso = '';
		$qAceite = (string)($_GET['aceite'] ?? '');
		if ($qAceite === 'erro') {
			$aviso = '<p style="color:#a00">'.htmlspecialchars((string)($_GET['msg'] ?? 'Não foi possível aceitar.'), ENT_QUOTES, 'UTF-8').'</p>';
		} elseif ($qAceite === 'ok') {
			$aviso = '<p style="color:#060">Contrato aceito.</p>';
		}
		if (!$diretor) {
			$barra = '<div id="barra-aceite">Apenas o diretor pode aceitar este contrato. Leia o texto e peça a ele para assinar.</div>';
		} elseif ($vigentes !== [] && !$pendente) {
			$quandoBr = self::dataBrAceite($quando);
			$barra = '<div id="barra-aceite"><label><input type="checkbox" checked disabled> Contrato aceito em '
				.htmlspecialchars($quandoBr, ENT_QUOTES, 'UTF-8').'.</label></div>';
		} elseif ($vigentes === []) {
			$barra = '';
		} else {
			$cpfAtual = '';
			$uidBarra = (int)($user['usuario']['id'] ?? 0);
			if ($uidBarra > 0) {
				$obCpf = \App\Model\Entity\User::getUserById($uidBarra);
				if ($obCpf instanceof \App\Model\Entity\User) {
					$cpfAtual = htmlspecialchars((string)($obCpf->cpf ?? ''), ENT_QUOTES, 'UTF-8');
				}
			}
			$barra = '<div id="barra-aceite">'.$aviso
				.'<p id="aviso-leitura">Role até o final do contrato para poder aceitar.</p>'
				.'<form method="post" action="'.URL.'/painel/assinatura/contrato/aceitar" id="form-aceite">'
				.'<label>CPF de quem assina <input type="text" name="cpf" value="'.$cpfAtual.'" required maxlength="14" style="margin:0 8px"></label> '
				.'<label><input type="checkbox" name="leu" value="1" id="chk-li" disabled> Li o contrato até o final e aceito os termos.</label> '
				.'<button type="submit" id="btn-aceitar" disabled>Aceitar contrato</button>'
				.'</form></div>'
				.'<script>
				(function(){
					var ok=false;
					function fim(){
						var el=document.documentElement;
						var curto=el.scrollHeight<=el.clientHeight+80;
						return curto || (el.scrollTop+el.clientHeight>=el.scrollHeight-48);
					}
					function liberar(){
						if(ok||!fim()) return;
						ok=true;
						var c=document.getElementById("chk-li");
						var a=document.getElementById("aviso-leitura");
						if(c) c.disabled=false;
						if(a) a.textContent="Marque que leu e aceite o contrato.";
					}
					window.addEventListener("scroll", liberar, {passive:true});
					setTimeout(liberar, 15000);
					var f=document.getElementById("form-aceite");
					if(f) f.addEventListener("submit", function(e){
						var c=document.getElementById("chk-li");
						if(!c||!c.checked){ e.preventDefault(); }
					});
					var c=document.getElementById("chk-li");
					if(c) c.addEventListener("change", function(){
						var b=document.getElementById("btn-aceitar");
						if(b) b.disabled=!c.checked;
					});
				})();
				</script>';
		}
		$css = '<style>#barra-aceite{position:sticky;bottom:0;background:#fff;border-top:1px solid #ccc;padding:12px 16px;font-family:sans-serif;font-size:14px}#barra-aceite button{margin-left:8px}@media print{#barra-aceite{display:none}}</style>';
		if (stripos($html, '</body>') !== false) {
			return str_ireplace('</body>', $css.$barra.'</body>', $html);
		}
		return $html.$css.$barra;
	}

	private static function dataBrAceite(string $iso): string {
		$iso = trim($iso);
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}:\d{2}))?/', $iso, $m)) {
			return $iso;
		}
		return $m[3].'/'.$m[2].'/'.$m[1].(isset($m[4]) ? ' '.$m[4] : '');
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
			case 'pagar_boleto':
				return self::pagarBoleto($post);
			case 'pagar_cartao':
				return self::pagarCartao($post);
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
		if ($escola instanceof ClientesAssinantes) {
			SaasContratoService::garantirFaturas($idAdmin);
		}
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
				'mp_public_key'                => MercadoPagoXd360Helper::publicKey(),
				'contratos'                    => SaasContratoService::listar($idAdmin),
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

	private static function faturaAbertaDoCliente(int $id, int $idAdmin): ?SaasFatura {
		$fat = SaasFatura::getById($id);
		if (!$fat instanceof SaasFatura || (int)$fat->id_admin !== $idAdmin || $fat->status === 'pago') {
			return null;
		}
		return $fat;
	}

	private static function dadosPagador(ClientesAssinantes $escola): array {
		return [
			'pagador_email'    => (string)$escola->email,
			'pagador_nome'     => (string)$escola->nome,
			'pagador_doc'      => (string)$escola->cpf_cnpj,
			'pagador_cpf'      => (string)$escola->cpf_cnpj,
			'pagador_endereco' => (string)$escola->endereco,
			'pagador_numero'   => (string)$escola->numero,
			'pagador_bairro'   => (string)$escola->bairro,
			'pagador_cidade'   => (string)($escola->cidade_nome ?? ''),
			'pagador_uf'       => (string)($escola->uf ?? ''),
			'pagador_cep'      => (string)$escola->cep,
		];
	}

	private static function pagarBoleto(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$fat = self::faturaAbertaDoCliente((int)($post['id'] ?? 0), $idAdmin);
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$fat || !$escola instanceof ClientesAssinantes) {
			return json_encode(['success' => false, 'message' => 'Fatura não encontrada.']);
		}
		$checkout = MercadoPagoXd360Helper::checkout();
		if (!$checkout) {
			return json_encode(['success' => false, 'message' => 'Mercado Pago indisponível.']);
		}
		$bol = $checkout->criarBoleto(self::dadosPagador($escola) + [
			'valor' => (float)$fat->valor,
			'descricao' => 'Assinatura XD360 '.$fat->competencia,
			'external_reference' => 'saas-'.$fat->id,
			'notification_url' => MercadoPagoXd360Helper::webhookUrl(),
			'vencimento' => (string)$fat->vencimento,
		]);
		if (!$bol) {
			return json_encode(['success' => false, 'message' => \App\Common\Gateways\MercadoPago\Checkout::getUltimoErro() ?: 'Falha ao gerar boleto.']);
		}
		$fat->mp_payment_id = $bol['id'];
		$fat->meio_pagamento = 'boleto';
		$fat->boleto_url = $bol['url'];
		$fat->boleto_linha = $bol['linha'];
		$fat->atualizar();
		return json_encode(['success' => true, 'message' => 'Boleto gerado. A compensação leva de 1 a 3 dias úteis.', 'fatura' => SaasAssinaturaService::formatar($fat)], JSON_UNESCAPED_UNICODE);
	}

	private static function pagarCartao(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$fat = self::faturaAbertaDoCliente((int)($post['id'] ?? 0), $idAdmin);
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$fat || !$escola instanceof ClientesAssinantes) {
			return json_encode(['success' => false, 'message' => 'Fatura não encontrada.']);
		}
		$checkout = MercadoPagoXd360Helper::checkout();
		if (!$checkout) {
			return json_encode(['success' => false, 'message' => 'Mercado Pago indisponível.']);
		}
		$pag = $checkout->criarCartao([
			'valor' => (float)$fat->valor,
			'token' => (string)($post['token'] ?? ''),
			'payment_method_id' => (string)($post['payment_method_id'] ?? ''),
			'issuer_id' => (string)($post['issuer_id'] ?? ''),
			'pagador_email' => (string)($post['email'] ?? $escola->email),
			'doc_type' => (string)($post['doc_type'] ?? 'CPF'),
			'doc_number' => (string)($post['doc_number'] ?? ''),
			'descricao' => 'Assinatura XD360 '.$fat->competencia,
			'external_reference' => 'saas-'.$fat->id,
			'notification_url' => MercadoPagoXd360Helper::webhookUrl(),
		]);
		if (!$pag) {
			return json_encode(['success' => false, 'message' => \App\Common\Gateways\MercadoPago\Checkout::getUltimoErro() ?: 'Falha no cartão.']);
		}
		$fat->mp_payment_id = $pag['id'];
		$fat->meio_pagamento = 'cartao';
		$fat->atualizar();
		if ($pag['status'] === 'approved') {
			SaasAssinaturaService::marcarPaga($fat);
			return json_encode(['success' => true, 'message' => 'Pagamento aprovado.', 'fatura' => SaasAssinaturaService::formatar($fat)], JSON_UNESCAPED_UNICODE);
		}
		$msg = $pag['status'] === 'in_process'
			? 'Pagamento em análise. A parcela continua em aberto até a confirmação.'
			: 'Pagamento não aprovado ('.$pag['status_detail'].'). A parcela continua em aberto.';
		return json_encode(['success' => false, 'message' => $msg, 'fatura' => SaasAssinaturaService::formatar($fat)], JSON_UNESCAPED_UNICODE);
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
