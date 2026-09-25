<?php

namespace App\Common\Helpers;

use App\Common\Communication\Email;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\PlanosAssinatura;
use App\Model\Entity\SaasFatura;
use App\Model\Db\Database;

class SaasAssinaturaService {

	public const GRACE_DIAS = 5;
	public const TRIAL_DIAS_DEFAULT = 14;

	public static function temColunasAssinaturaEscola(): bool {
		return ClientesAssinantes::temColunasAssinatura();
	}

	public static function temColunaValorMensal(): bool {
		return PlanosAssinatura::temColunaValorMensal();
	}

	/** Valor efetivo: custom da escola >0, senão valor do plano. */
	public static function resolverValorMensal(ClientesAssinantes $escola): float {
		if (ClientesAssinantes::temColunaValorMensalCustom()) {
			$custom = (float)($escola->valor_mensal_custom ?? 0);
			if ($custom > 0) {
				return round($custom, 2);
			}
		}
		$planId = ClientesAssinantes::temColunaPlanId() ? (int)($escola->plan_id ?? 0) : 0;
		if ($planId > 0 && self::temColunaValorMensal()) {
			$plano = PlanosAssinatura::getById($planId);
			if ($plano instanceof PlanosAssinatura) {
				return round((float)($plano->valor_mensal ?? 0), 2);
			}
		}
		return 0.0;
	}

	/**
	 * Itens da fatura: plano + licenças vitrine + taxa CTI.
	 * @return array{itens: array<int,array>, total: float}
	 */
	public static function montarItensFatura(int $idAdmin, float $valorPlano): array {
		$itens = [];
		$total = 0.0;
		if ($valorPlano > 0) {
			$itens[] = [
				'tipo' => 'plano_painel',
				'descricao' => 'Assinatura Painel CTI',
				'valor' => round($valorPlano, 2),
				'id_curso' => null,
				'id_vitrine_assinatura' => null,
				'id_escola_criadora' => null,
			];
			$total += $valorPlano;
		}

		if (\App\Model\Entity\LmsVitrineAssinatura::tabelaExiste()) {
			$taxa = \App\Model\Entity\LmsVitrineConfig::taxaCtiMensal();
			foreach (\App\Model\Entity\LmsVitrineAssinatura::listAtivasEscola($idAdmin) as $ass) {
				$curso = \App\Model\Entity\LmsCurso::getById((int)$ass->id_curso);
				if (!$curso instanceof \App\Model\Entity\LmsCurso) {
					continue;
				}
				$preco = round((float)($curso->vitrine_preco_mensal ?? 0), 2);
				if ($preco > 0) {
					$itens[] = [
						'tipo' => 'licenca_curso',
						'descricao' => 'Licença EAD: '.$curso->nomeExibicao(),
						'valor' => $preco,
						'id_curso' => (int)$curso->id,
						'id_vitrine_assinatura' => (int)$ass->id,
						'id_escola_criadora' => (int)$ass->id_escola_criadora,
					];
					$total += $preco;
				}
				if ($taxa > 0) {
					$itens[] = [
						'tipo' => 'taxa_vitrine_cti',
						'descricao' => 'Taxa CTI vitrine: '.$curso->nomeExibicao(),
						'valor' => round($taxa, 2),
						'id_curso' => (int)$curso->id,
						'id_vitrine_assinatura' => (int)$ass->id,
						'id_escola_criadora' => null,
					];
					$total += $taxa;
				}
			}
		}

		return ['itens' => $itens, 'total' => round($total, 2)];
	}

	/** Escola elegível a cobrança automática (plano ou licenças vitrine). */
	public static function escolaCobravel(ClientesAssinantes $escola): bool {
		$montagem = self::montarItensFatura((int)$escola->id, self::resolverValorMensal($escola));
		return $montagem['total'] > 0;
	}

	/** Em trial válido (não gera fatura / não suspende por inadimplência do trial). */
	public static function emTrialAtivo(ClientesAssinantes $escola): bool {
		if (!self::temColunasAssinaturaEscola()) {
			return false;
		}
		$status = (string)($escola->assinatura_status ?? '');
		if ($status !== 'trial') {
			return false;
		}
		if (!ClientesAssinantes::temColunaTrialAte()) {
			return true;
		}
		$ate = trim((string)($escola->trial_ate ?? ''));
		if ($ate === '') {
			return true;
		}
		return $ate >= date('Y-m-d');
	}

	/** Trial acabou: status ainda trial mas trial_ate passou. */
	public static function trialExpirado(ClientesAssinantes $escola): bool {
		if (!self::temColunasAssinaturaEscola()) {
			return false;
		}
		if ((string)($escola->assinatura_status ?? '') !== 'trial') {
			return false;
		}
		if (!ClientesAssinantes::temColunaTrialAte()) {
			return false;
		}
		$ate = trim((string)($escola->trial_ate ?? ''));
		return $ate !== '' && $ate < date('Y-m-d');
	}

	public static function encerrarTrialSeExpirado(ClientesAssinantes $escola): void {
		if (!self::trialExpirado($escola)) {
			return;
		}
		(new Database('clientes_assinantes'))->update('id = '.(int)$escola->id, [
			'assinatura_status' => 'ativa',
		]);
		$escola->assinatura_status = 'ativa';
		ModuleGateHelper::limparCache((int)$escola->id);
	}

	/**
	 * Aplica trial padrão (14 dias) na criação — Master pode sobrescrever trial_ate.
	 */
	public static function aplicarTrialPadrao(ClientesAssinantes $escola, ?string $trialAte = null): void {
		if (!self::temColunasAssinaturaEscola()) {
			return;
		}
		$escola->assinatura_status = 'trial';
		if (ClientesAssinantes::temColunaTrialAte()) {
			if ($trialAte && preg_match('/^\d{4}-\d{2}-\d{2}$/', $trialAte)) {
				$escola->trial_ate = $trialAte;
			} else {
				$escola->trial_ate = date('Y-m-d', strtotime('+'.self::TRIAL_DIAS_DEFAULT.' days'));
			}
		}
	}

	/**
	 * Gera (ou reutiliza) fatura do mês + PIX CTI + e-mail (1×).
	 * @return array{ok:bool,message:string,fatura?:array}
	 */
	public static function gerarFaturaEscola(int $idAdmin, ?string $competencia = null, bool $forcarEmail = false): array {
		if (!SaasFatura::tabelaExiste()) {
			return ['ok' => false, 'message' => 'Execute database/saas_assinatura.sql'];
		}
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola instanceof ClientesAssinantes) {
			return ['ok' => false, 'message' => 'Escola não encontrada.'];
		}

		self::encerrarTrialSeExpirado($escola);

		if (\App\Model\Entity\SaasContrato::tabelaExiste()) {
			SaasContratoService::garantirFaturas($idAdmin);
			$vigentes = \App\Model\Entity\SaasContrato::vigentesDoCliente($idAdmin);
			if (!empty($vigentes)) {
				$aberta = SaasFatura::get(
					'id_admin = '.$idAdmin.' AND status IN ("aberta","vencida")',
					'vencimento ASC',
					'1'
				)->fetchObject(SaasFatura::class);
				if ($aberta instanceof SaasFatura) {
					if (empty($aberta->pix_copia_cola) && empty($aberta->boleto_url) && empty($aberta->mp_payment_id)) {
						self::anexarPix($aberta, $escola);
					}
					return ['ok' => true, 'message' => 'Parcela do contrato disponível.', 'fatura' => self::formatar($aberta, $escola)];
				}
				return ['ok' => true, 'message' => 'Nenhuma parcela em aberto.', 'fatura' => null];
			}
		}

		if (self::emTrialAtivo($escola)) {
			return [
				'ok' => false,
				'message' => 'Escola em trial até '.($escola->trial_ate ?: '—').'. Cobrança começa após o trial.',
			];
		}

		$competencia = $competencia ?: date('Y-m');
		if (!preg_match('/^\d{4}-\d{2}$/', $competencia)) {
			return ['ok' => false, 'message' => 'Competência inválida.'];
		}

		$existente = SaasFatura::getPorEscolaCompetencia($idAdmin, $competencia);
		if ($existente instanceof SaasFatura) {
			if ($existente->status === 'pago') {
				return ['ok' => true, 'message' => 'Fatura já paga.', 'fatura' => self::formatar($existente, $escola)];
			}
			if (empty($existente->pix_copia_cola) || empty($existente->mp_payment_id)) {
				self::anexarPix($existente, $escola);
			}
			self::enviarEmailCobranca($existente, $escola, $forcarEmail);
			return ['ok' => true, 'message' => 'Fatura já existia.', 'fatura' => self::formatar($existente, $escola)];
		}

		$valorPlano = self::resolverValorMensal($escola);
		$montagem = self::montarItensFatura($idAdmin, $valorPlano);
		$valor = $montagem['total'];
		if ($valor <= 0) {
			return ['ok' => false, 'message' => 'Sem valor mensal. Defina preço no plano, valor custom ou licenças na vitrine.'];
		}

		$planId = ClientesAssinantes::temColunaPlanId() ? (int)($escola->plan_id ?? 0) : 0;

		$dia = self::temColunasAssinaturaEscola()
			? max(1, min(28, (int)($escola->dia_vencimento_assinatura ?? 10)))
			: 10;
		[$ano, $mes] = explode('-', $competencia);
		$vencimento = sprintf('%04d-%02d-%02d', (int)$ano, (int)$mes, $dia);
		if (!checkdate((int)$mes, $dia, (int)$ano)) {
			$vencimento = date('Y-m-t', strtotime($ano.'-'.$mes.'-01'));
		}

		$fat = new SaasFatura;
		$fat->id_admin = $idAdmin;
		$fat->plan_id = $planId > 0 ? $planId : null;
		$fat->competencia = $competencia;
		$fat->valor = $valor;
		$fat->vencimento = $vencimento;
		$fat->status = 'aberta';
		if (!$fat->cadastrar()) {
			return ['ok' => false, 'message' => 'Falha ao criar fatura.'];
		}

		if (\App\Model\Entity\SaasFaturaItem::tabelaExiste()) {
			foreach ($montagem['itens'] as $row) {
				$item = new \App\Model\Entity\SaasFaturaItem();
				$item->id_fatura = (int)$fat->id;
				$item->tipo = $row['tipo'];
				$item->descricao = $row['descricao'];
				$item->valor = $row['valor'];
				$item->id_curso = $row['id_curso'];
				$item->id_vitrine_assinatura = $row['id_vitrine_assinatura'];
				$item->id_escola_criadora = $row['id_escola_criadora'];
				$item->cadastrar();
			}
		}

		$pixOk = self::anexarPix($fat, $escola);
		self::atualizarProximoVencimentoEscola($escola, $vencimento);
		$emailOk = self::enviarEmailCobranca($fat, $escola, true);

		$msg = 'Fatura gerada.';
		if (!$pixOk) {
			$err = \App\Common\Gateways\MercadoPago\Pix::getUltimoErro();
			$msg = MercadoPagoXd360Helper::configurado()
				? ('Fatura criada, mas PIX falhou'.($err ? ': '.$err : '.'))
				: 'Fatura criada. Configure MP_CTI_ACCESS_TOKEN para gerar PIX.';
		}
		if (!$emailOk) {
			$msg .= ' (e-mail de cobrança não enviado)';
		}
		return ['ok' => true, 'message' => $msg, 'fatura' => self::formatar($fat, $escola)];
	}

	public static function anexarPix(SaasFatura $fat, ?ClientesAssinantes $escola = null): bool {
		$pix = MercadoPagoXd360Helper::pix();
		if (!$pix) {
			return false;
		}
		if (!$escola instanceof ClientesAssinantes) {
			$escola = ClientesAssinantes::getEscolaById((int)$fat->id_admin);
		}
		$nomeEscola = $escola instanceof ClientesAssinantes ? (string)$escola->nome : 'Escola';
		$email = $escola instanceof ClientesAssinantes ? trim((string)($escola->email ?? '')) : '';
		$fallback = MercadoPagoXd360Helper::payerEmailFallback();

		$cob = $pix->criarCobrancaPix([
			'valor'                => $fat->valor,
			'descricao'            => 'Assinatura Painel CTI '.$fat->competencia.' — '.$nomeEscola,
			'vencimento'           => $fat->vencimento,
			'external_reference'   => 'saas:'.(int)$fat->id,
			'notification_url'     => MercadoPagoXd360Helper::webhookUrl(),
			'statement_descriptor' => 'CTI ASSINATURA',
			'pagador_nome'         => $nomeEscola,
			'pagador_email'        => $email,
			'email_fallback'       => $fallback,
		]);
		if (!is_array($cob) || empty($cob['id']) || empty($cob['copia_cola'])) {
			return false;
		}
		$fat->mp_payment_id = $cob['id'];
		$fat->meio_pagamento = 'pix';
		$fat->pix_copia_cola = $cob['copia_cola'];
		if (SaasFatura::temColunaPixQrBase64() && !empty($cob['qr_base64'])) {
			$fat->pix_qr_base64 = (string)$cob['qr_base64'];
		}
		$fat->atualizar();
		return true;
	}

	/** Envia e-mail 1× por fatura (SMTP sistema). */
	public static function enviarEmailCobranca(SaasFatura $fat, ?ClientesAssinantes $escola = null, bool $forcar = false): bool {
		if ($fat->status === 'pago') {
			return true;
		}
		if (!$forcar && SaasFatura::temColunaEmailEnviado() && !empty($fat->email_enviado_em)) {
			return true;
		}
		if (!$escola instanceof ClientesAssinantes) {
			$escola = ClientesAssinantes::getEscolaById((int)$fat->id_admin);
		}
		if (!$escola instanceof ClientesAssinantes) {
			return false;
		}
		$to = trim((string)($escola->email ?? ''));
		if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
			return false;
		}

		$urlPainel = rtrim((string)(defined('URL') ? URL : ''), '/').'/painel/assinatura';
		$valorBr = number_format((float)$fat->valor, 2, ',', '.');
		$copia = trim((string)($fat->pix_copia_cola ?? ''));
		$body = '<p>Olá, <strong>'.htmlspecialchars((string)$escola->nome, ENT_QUOTES, 'UTF-8').'</strong>.</p>'
			.'<p>Sua fatura da assinatura do <strong>Painel CTI</strong> está disponível.</p>'
			.'<ul>'
			.'<li>Competência: <strong>'.htmlspecialchars((string)$fat->competencia, ENT_QUOTES, 'UTF-8').'</strong></li>'
			.'<li>Valor: <strong>R$ '.$valorBr.'</strong></li>'
			.'<li>Vencimento: <strong>'.htmlspecialchars((string)$fat->vencimento, ENT_QUOTES, 'UTF-8').'</strong></li>'
			.'</ul>'
			.'<p>Acesse o painel para pagar com PIX: <a href="'.htmlspecialchars($urlPainel, ENT_QUOTES, 'UTF-8').'">'.$urlPainel.'</a></p>';
		if ($copia !== '') {
			$body .= '<p><small>PIX copia e cola:</small><br><code style="word-break:break-all">'.htmlspecialchars($copia, ENT_QUOTES, 'UTF-8').'</code></p>';
		}
		$body .= '<p>Após '.self::GRACE_DIAS.' dias do vencimento sem pagamento, o acesso fica restrito à tela de Assinatura.</p>';

		$mail = Email::sistema();
		$ok = $mail->sendEmail(
			[$to],
			'Assinatura Painel CTI — fatura '.$fat->competencia,
			$body
		);
		if ($ok && SaasFatura::temColunaEmailEnviado()) {
			$fat->email_enviado_em = date('Y-m-d H:i:s');
			$fat->atualizar();
		}
		return (bool)$ok;
	}

	public static function marcarPaga(SaasFatura $fat, ?string $pagoEm = null): bool {
		$fat->status = 'pago';
		$fat->pago_em = $pagoEm ?: date('Y-m-d H:i:s');
		$fat->atualizar();
		if (!empty($fat->contrato_id) && !empty($fat->numero_parcela)) {
			try {
				(new Database('saas_contrato_parcelas'))->execute(
					'UPDATE saas_contrato_parcelas SET status = "paga" WHERE contrato_id = '.(int)$fat->contrato_id
					.' AND numero = '.(int)$fat->numero_parcela
				);
			} catch (\Throwable $e) {
			}
		}

		\App\Model\Entity\LmsVitrineRepasse::gerarDeFaturaPaga((int)$fat->id, (string)$fat->competencia);

		$escola = ClientesAssinantes::getEscolaById((int)$fat->id_admin);
		if ($escola instanceof ClientesAssinantes) {
			$escola->ativo = 's';
			if (self::temColunasAssinaturaEscola()) {
				$escola->assinatura_status = 'ativa';
				$escola->assinatura_proximo_vencimento = $fat->vencimento;
				$upd = [
					'ativo' => 's',
					'assinatura_status' => 'ativa',
					'assinatura_proximo_vencimento' => $fat->vencimento,
				];
				(new Database('clientes_assinantes'))->update('id = '.(int)$escola->id, $upd);
			} else {
				$escola->atualizar();
			}
			ModuleGateHelper::limparCache((int)$escola->id);
		}
		return true;
	}

	/** Processa escolas: trial → fatura do mês → e-mail → suspende após grace. */
	public static function processar(?int $idAdminFiltro = null): array {
		$resumo = [
			'geradas'     => 0,
			'suspensas'   => 0,
			'emails'      => 0,
			'trials'      => 0,
			'erros'       => [],
			'mp_ok'       => MercadoPagoXd360Helper::configurado(),
			'tabela_ok'   => SaasFatura::tabelaExiste(),
		];
		if (!$resumo['tabela_ok']) {
			$resumo['erros'][] = 'Tabela saas_faturas ausente.';
			return $resumo;
		}

		$where = '1=1';
		if ($idAdminFiltro !== null && $idAdminFiltro > 0) {
			$where = 'id = '.(int)$idAdminFiltro;
		}
		$results = ClientesAssinantes::getEscolas($where, 'id ASC');
		$competencia = date('Y-m');

		while ($e = $results->fetchObject(ClientesAssinantes::class)) {
			$id = (int)$e->id;
			if ($id <= 0) {
				continue;
			}

			self::encerrarTrialSeExpirado($e);

			if (self::emTrialAtivo($e)) {
				$resumo['trials']++;
				continue;
			}

			if (!self::escolaCobravel($e)) {
				continue;
			}

			$r = self::gerarFaturaEscola($id, $competencia);
			if ($r['ok']) {
				$resumo['geradas']++;
				$fat = SaasFatura::getPorEscolaCompetencia($id, $competencia);
				if ($fat instanceof SaasFatura && SaasFatura::temColunaEmailEnviado() && !empty($fat->email_enviado_em)) {
					$resumo['emails']++;
				}
			} else {
				$resumo['erros'][] = '#'.$id.' '.$e->nome.': '.$r['message'];
			}

			$e2 = ClientesAssinantes::getEscolaById($id);
			if ($e2 instanceof ClientesAssinantes && self::suspenderSeInadimplente($e2)) {
				$resumo['suspensas']++;
			}
		}

		return $resumo;
	}

	public static function suspenderSeInadimplente(ClientesAssinantes $escola): bool {
		if (!SaasFatura::tabelaExiste()) {
			return false;
		}
		if (self::emTrialAtivo($escola)) {
			return false;
		}
		$hoje = date('Y-m-d');
		$limite = date('Y-m-d', strtotime('-'.self::GRACE_DIAS.' days'));

		$aberta = SaasFatura::get(
			'id_admin = '.(int)$escola->id.' AND status = "aberta" AND vencimento < "'.addslashes($limite).'"',
			'vencimento ASC',
			'1'
		)->fetchObject(SaasFatura::class);

		if (!$aberta instanceof SaasFatura) {
			return false;
		}

		if ($escola->isAtiva()) {
			(new Database('clientes_assinantes'))->update('id = '.(int)$escola->id, [
				'ativo' => 'n',
			] + (self::temColunasAssinaturaEscola() ? ['assinatura_status' => 'suspensa'] : []));
			ModuleGateHelper::limparCache((int)$escola->id);
		}

		if ($aberta->status === 'aberta' && $aberta->vencimento < $hoje) {
			$aberta->status = 'vencida';
			$aberta->atualizar();
		}

		return true;
	}

	/** Cards do dashboard Master. */
	public static function dashboardStats(): array {
		$hoje = date('Y-m-d');
		$comp = date('Y-m');
		$stats = [
			'escolas_ativas'    => 0,
			'escolas_trial'     => 0,
			'escolas_suspensas' => 0,
			'faturas_abertas'   => 0,
			'faturas_vencidas'  => 0,
			'receita_mes'       => 0.0,
			'competencia'       => $comp,
		];

		$results = ClientesAssinantes::getEscolas(null, 'id ASC');
		while ($e = $results->fetchObject(ClientesAssinantes::class)) {
			if (self::emTrialAtivo($e)) {
				$stats['escolas_trial']++;
			} elseif (!$e->isAtiva() || (string)($e->assinatura_status ?? '') === 'suspensa') {
				$stats['escolas_suspensas']++;
			} else {
				$stats['escolas_ativas']++;
			}
		}

		if (SaasFatura::tabelaExiste()) {
			$row = SaasFatura::get('status = "aberta"', null, null, 'COUNT(*) AS q')->fetch(\PDO::FETCH_ASSOC);
			$stats['faturas_abertas'] = (int)($row['q'] ?? 0);
			$row = SaasFatura::get('status = "vencida"', null, null, 'COUNT(*) AS q')->fetch(\PDO::FETCH_ASSOC);
			$stats['faturas_vencidas'] = (int)($row['q'] ?? 0);
			$row = SaasFatura::get(
				'status = "pago" AND competencia = "'.addslashes($comp).'"',
				null,
				null,
				'SUM(valor) AS t'
			)->fetch(\PDO::FETCH_ASSOC);
			$stats['receita_mes'] = round((float)($row['t'] ?? 0), 2);
		}

		$stats['receita_mes_br'] = number_format($stats['receita_mes'], 2, ',', '.');
		$stats['hoje'] = $hoje;
		return $stats;
	}

	private static function atualizarProximoVencimentoEscola(ClientesAssinantes $escola, string $vencimento): void {
		if (!self::temColunasAssinaturaEscola()) {
			return;
		}
		(new Database('clientes_assinantes'))->update('id = '.(int)$escola->id, [
			'assinatura_proximo_vencimento' => $vencimento,
		]);
	}

	public static function formatar(SaasFatura $f, ?ClientesAssinantes $escola = null): array {
		if (!$escola instanceof ClientesAssinantes) {
			$escola = ClientesAssinantes::getEscolaById((int)$f->id_admin);
		}
		$copia = trim((string)($f->pix_copia_cola ?? ''));
		$qrImg = self::pixQrImageSrc($f);
		$venc = (string)$f->vencimento;
		$pago = $f->pago_em ?? null;
		$emailEm = $f->email_enviado_em ?? null;
		return [
			'id'             => (int)$f->id,
			'id_admin'       => (int)$f->id_admin,
			'escola_nome'    => $escola instanceof ClientesAssinantes ? (string)$escola->nome : '',
			'plan_id'        => $f->plan_id !== null ? (int)$f->plan_id : null,
			'competencia'    => (string)$f->competencia,
			'valor'          => round((float)$f->valor, 2),
			'valor_br'       => number_format((float)$f->valor, 2, ',', '.'),
			'vencimento'     => $venc,
			'vencimento_br'  => self::formatarDataBr($venc),
			'status'         => (string)$f->status,
			'mp_payment_id'  => (string)($f->mp_payment_id ?? ''),
			'pix_copia_cola' => $copia,
			'pix_qr_src'     => $qrImg,
			'pago_em'        => $pago,
			'pago_em_br'     => self::formatarDataBr($pago, true),
			'email_enviado_em' => $emailEm,
			'email_enviado_em_br' => self::formatarDataBr($emailEm, true),
			'tem_pix'        => $copia !== '',
			'meio_pagamento' => (string)($f->meio_pagamento ?? ''),
			'boleto_url'     => (string)($f->boleto_url ?? ''),
			'boleto_linha'   => (string)($f->boleto_linha ?? ''),
		];
	}

	private static function formatarDataBr($data, bool $comHora = false): ?string {
		if ($data === null || $data === '') {
			return null;
		}
		try {
			$s = (string)$data;
			$br = \App\Common\Helpers\DateTimeHelper::databr($s);
			if ($comHora && strlen($s) > 10) {
				$br .= ' '.substr(\App\Common\Helpers\DateTimeHelper::extrairHorario($s), 0, 5);
			}
			return $br;
		} catch (\Throwable $e) {
			return (string)$data;
		}
	}

	/** data URI do MP ou URL gerada a partir do copia-e-cola */
	public static function pixQrImageSrc(SaasFatura $f): string {
		if (SaasFatura::temColunaPixQrBase64()) {
			$b64 = trim((string)($f->pix_qr_base64 ?? ''));
			if ($b64 !== '') {
				if (strpos($b64, 'data:image') === 0) {
					return $b64;
				}
				return 'data:image/png;base64,'.$b64;
			}
		}
		$copia = trim((string)($f->pix_copia_cola ?? ''));
		if ($copia === '') {
			return '';
		}
		return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&ecc=M&margin=8&data='.rawurlencode($copia);
	}
}
