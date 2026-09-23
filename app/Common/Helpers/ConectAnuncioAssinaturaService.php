<?php

namespace App\Common\Helpers;

use App\Common\Gateways\MercadoPago\Pix;
use App\Model\Entity\CjAnuncio;
use App\Model\Entity\CjAnuncioAssinatura;
use App\Model\Entity\CjAnuncioConfig;
use App\Model\Entity\CjAnuncioFatura;
use App\Model\Entity\CjAnuncioPlano;
use App\Model\Entity\CjEmpresa;

class ConectAnuncioAssinaturaService {

	public const GRACE_DIAS = 5;

	public static function moduloAtivo(): bool {
		return CjAnuncioPlano::tabelaExiste()
			&& CjAnuncioAssinatura::tabelaExiste()
			&& CjAnuncioFatura::tabelaExiste();
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarPlanosPublicos(): array {
		if (!self::moduloAtivo()) {
			return [];
		}
		$out = [];
		foreach (CjAnuncioPlano::listarAtivos() as $plano) {
			$out[] = $plano->toArray();
		}
		return $out;
	}

	public static function temAssinaturaAtiva(int $idEmpresa): bool {
		if (!self::moduloAtivo() || $idEmpresa <= 0) {
			return false;
		}
		$ass = CjAnuncioAssinatura::getAtivaPorEmpresa($idEmpresa);
		if (!$ass instanceof CjAnuncioAssinatura) {
			return false;
		}
		if (!empty($ass->fim_em) && strtotime((string)$ass->fim_em) < time()) {
			return false;
		}
		return true;
	}

	public static function limiteAnuncios(int $idEmpresa): int {
		if (!self::moduloAtivo()) {
			$config = CjAnuncioConfig::get();
			return max(1, (int)($config['max_anuncios_por_empresa'] ?? 3));
		}
		$ass = CjAnuncioAssinatura::getAtivaPorEmpresa($idEmpresa);
		if (!$ass instanceof CjAnuncioAssinatura) {
			return 0;
		}
		$plano = CjAnuncioPlano::getById((int)$ass->plan_id);
		return $plano instanceof CjAnuncioPlano ? max(1, (int)$plano->max_anuncios) : 0;
	}

	public static function contarAnunciosPlano(int $idEmpresa): int {
		return CjAnuncio::contarOcupandoPlano($idEmpresa);
	}

	public static function podeCriarAnuncio(int $idEmpresa): bool {
		if (!self::moduloAtivo()) {
			$config = CjAnuncioConfig::get();
			$max = max(1, (int)($config['max_anuncios_por_empresa'] ?? 3));
			return CjAnuncio::contarOcupandoPlano($idEmpresa) < $max;
		}
		if (!self::temAssinaturaAtiva($idEmpresa)) {
			return false;
		}
		$limite = self::limiteAnuncios($idEmpresa);
		if ($limite <= 0) {
			return false;
		}
		return self::contarAnunciosPlano($idEmpresa) < $limite;
	}

	/** @return array<string,mixed> */
	public static function resumoEmpresa(int $idEmpresa): array {
		if (!self::moduloAtivo()) {
			return [
				'moduloAtivo'      => false,
				'temAssinatura'    => false,
				'assinaturaAtiva'  => false,
				'limiteAnuncios'   => self::limiteAnuncios($idEmpresa),
				'usados'           => CjAnuncio::contarOcupandoPlano($idEmpresa),
				'podeCriar'        => self::podeCriarAnuncio($idEmpresa),
			];
		}

		$ass = CjAnuncioAssinatura::getVigentePorEmpresa($idEmpresa);
		$plano = null;
		$faturaAberta = null;
		if ($ass instanceof CjAnuncioAssinatura) {
			$p = CjAnuncioPlano::getById((int)$ass->plan_id);
			if ($p instanceof CjAnuncioPlano) {
				$plano = $p->toArray();
			}
			if (($ass->status ?? '') === 'pendente') {
				$fat = CjAnuncioFatura::getAbertaPorAssinatura((int)$ass->id);
				if ($fat instanceof CjAnuncioFatura) {
					$faturaAberta = self::formatarFatura($fat);
				}
			}
		}

		$ativa = self::temAssinaturaAtiva($idEmpresa);
		$limite = self::limiteAnuncios($idEmpresa);
		$usadosPlano = self::contarAnunciosPlano($idEmpresa);
		return [
			'moduloAtivo'         => true,
			'temAssinatura'       => $ass instanceof CjAnuncioAssinatura,
			'assinaturaAtiva'     => $ativa,
			'assinatura'          => $ass ? self::formatarAssinatura($ass, $plano) : null,
			'faturaAberta'        => $faturaAberta,
			'limiteAnuncios'      => $limite,
			'usados'              => $usadosPlano,
			'usadosTotal'         => CjAnuncio::contarPorEmpresa($idEmpresa),
			'limiteExcedido'      => $ativa && $limite > 0 && $usadosPlano > $limite,
			'podeCriar'           => self::podeCriarAnuncio($idEmpresa),
			'mpConfigurado'       => MercadoPagoXd360Helper::configurado(),
		];
	}

	/** @return array{ok:bool,message?:string,assinatura?:array<string,mixed>,fatura?:array<string,mixed>} */
	public static function assinar(int $idEmpresa, int $planId): array {
		if (!self::moduloAtivo()) {
			return ['ok' => false, 'message' => 'Módulo de assinatura não instalado. Execute database/cj_anuncio_assinatura.sql.'];
		}
		$empresa = CjEmpresa::getById($idEmpresa);
		if (!$empresa instanceof CjEmpresa) {
			return ['ok' => false, 'message' => 'Empresa não encontrada.'];
		}
		$plano = CjAnuncioPlano::getById($planId);
		if (!$plano instanceof CjAnuncioPlano || (int)$plano->ativo !== 1) {
			return ['ok' => false, 'message' => 'Plano inválido ou indisponível.'];
		}

		$existente = CjAnuncioAssinatura::getVigentePorEmpresa($idEmpresa);
		if ($existente instanceof CjAnuncioAssinatura) {
			if (($existente->status ?? '') === 'ativa') {
				return ['ok' => false, 'message' => 'Você já possui assinatura ativa. Cancele ou aguarde o fim do período para trocar de plano.'];
			}
			if (($existente->status ?? '') === 'pendente') {
				$fat = CjAnuncioFatura::getAbertaPorAssinatura((int)$existente->id);
				if ($fat instanceof CjAnuncioFatura) {
					self::anexarPix($fat, $empresa, $plano);
					return [
						'ok'         => true,
						'message'    => 'Assinatura pendente — conclua o pagamento PIX.',
						'assinatura' => self::formatarAssinatura($existente, $plano->toArray()),
						'fatura'     => self::formatarFatura($fat),
					];
				}
			}
		}

		$ass = new CjAnuncioAssinatura();
		$ass->id_empresa = $idEmpresa;
		$ass->plan_id = (int)$plano->id;
		$ass->status = 'pendente';
		if (!$ass->cadastrar()) {
			return ['ok' => false, 'message' => 'Falha ao criar assinatura.'];
		}

		$competencia = date('Y-m');
		$vencimento = date('Y-m-d', strtotime('+3 days'));
		$fat = new CjAnuncioFatura();
		$fat->id_assinatura = (int)$ass->id;
		$fat->id_empresa = $idEmpresa;
		$fat->plan_id = (int)$plano->id;
		$fat->competencia = $competencia;
		$fat->valor = (float)$plano->valor_mensal;
		$fat->vencimento = $vencimento;
		$fat->status = 'aberta';
		if (!$fat->cadastrar()) {
			return ['ok' => false, 'message' => 'Falha ao gerar fatura.'];
		}

		$pixOk = self::anexarPix($fat, $empresa, $plano);
		$msg = $pixOk
			? 'Assinatura criada. Pague o PIX para ativar seus anúncios.'
			: 'Assinatura criada. Configure o Mercado Pago CTI para gerar PIX.';

		return [
			'ok'         => true,
			'message'    => $msg,
			'assinatura' => self::formatarAssinatura($ass, $plano->toArray()),
			'fatura'     => self::formatarFatura($fat),
		];
	}

	/** @return array{ok:bool,message?:string} */
	public static function cancelar(int $idEmpresa): array {
		if (!self::moduloAtivo()) {
			return ['ok' => false, 'message' => 'Módulo não instalado.'];
		}
		$ass = CjAnuncioAssinatura::getVigentePorEmpresa($idEmpresa);
		if (!$ass instanceof CjAnuncioAssinatura || !in_array($ass->status ?? '', ['ativa', 'pendente'], true)) {
			return ['ok' => false, 'message' => 'Nenhuma assinatura ativa para cancelar.'];
		}

		$wasAtiva = ($ass->status ?? '') === 'ativa';
		$ass->status = 'cancelada';
		$ass->cancelada_em = date('Y-m-d H:i:s');
		if ($wasAtiva && !empty($ass->proximo_vencimento)) {
			$ass->fim_em = date('Y-m-d 23:59:59', strtotime((string)$ass->proximo_vencimento));
		} else {
			$ass->fim_em = date('Y-m-d H:i:s');
		}
		$ass->atualizar();

		return ['ok' => true, 'message' => 'Assinatura cancelada. Seus anúncios permanecem até o fim do período pago.'];
	}

	/** @return array{ok:bool,message?:string,pago?:bool,fatura?:array<string,mixed>} */
	public static function verificarPagamento(int $idEmpresa, int $faturaId): array {
		if (!self::moduloAtivo()) {
			return ['ok' => false, 'message' => 'Módulo não instalado.'];
		}
		$fat = CjAnuncioFatura::getById($faturaId);
		if (!$fat instanceof CjAnuncioFatura || (int)$fat->id_empresa !== $idEmpresa) {
			return ['ok' => false, 'message' => 'Fatura não encontrada.'];
		}
		if (($fat->status ?? '') === 'pago') {
			return [
				'ok'     => true,
				'pago'   => true,
				'message'=> 'Pagamento confirmado.',
				'fatura' => self::formatarFatura($fat),
				'resumo' => self::resumoEmpresa($idEmpresa),
			];
		}

		$pix = MercadoPagoXd360Helper::pix();
		if (!$pix instanceof Pix || empty($fat->mp_payment_id)) {
			return ['ok' => true, 'pago' => false, 'message' => 'Aguardando pagamento.'];
		}

		$pagamento = $pix->consultarPagamento((string)$fat->mp_payment_id);
		if (is_array($pagamento) && ($pagamento['status'] ?? '') === 'approved') {
			self::marcarPaga($fat, !empty($pagamento['date_approved'])
				? (new \DateTimeImmutable((string)$pagamento['date_approved']))->format('Y-m-d H:i:s')
				: null);
			$fat = CjAnuncioFatura::getById((int)$fat->id);
			return [
				'ok'     => true,
				'pago'   => true,
				'message'=> 'Pagamento confirmado!',
				'fatura' => self::formatarFatura($fat),
				'resumo' => self::resumoEmpresa($idEmpresa),
			];
		}

		return ['ok' => true, 'pago' => false, 'message' => 'Pagamento ainda não identificado.', 'resumo' => self::resumoEmpresa($idEmpresa)];
	}

	public static function marcarPaga(CjAnuncioFatura $fat, ?string $pagoEm = null): bool {
		if (($fat->status ?? '') === 'pago') {
			return true;
		}
		$fat->status = 'pago';
		$fat->pago_em = $pagoEm ?: date('Y-m-d H:i:s');
		$fat->atualizar();

		$ass = CjAnuncioAssinatura::getById((int)$fat->id_assinatura);
		if ($ass instanceof CjAnuncioAssinatura) {
			$ass->status = 'ativa';
			if (empty($ass->inicio_em)) {
				$ass->inicio_em = $fat->pago_em;
			}
			$prox = date('Y-m-d', strtotime('+1 month', strtotime(substr((string)$fat->pago_em, 0, 10))));
			$ass->proximo_vencimento = $prox;
			$ass->fim_em = null;
			$ass->atualizar();
		}
		return true;
	}

	public static function anexarPix(CjAnuncioFatura $fat, ?CjEmpresa $empresa = null, ?CjAnuncioPlano $plano = null): bool {
		$pix = MercadoPagoXd360Helper::pix();
		if (!$pix instanceof Pix) {
			return false;
		}
		if (!$empresa instanceof CjEmpresa) {
			$empresa = CjEmpresa::getById((int)$fat->id_empresa);
		}
		if (!$plano instanceof CjAnuncioPlano) {
			$plano = CjAnuncioPlano::getById((int)$fat->plan_id);
		}
		$nome = $empresa instanceof CjEmpresa ? (string)($empresa->nome_fantasia ?: $empresa->razao_social) : 'Empresa';
		$email = $empresa instanceof CjEmpresa ? trim((string)($empresa->email ?? '')) : '';
		$planoNome = $plano instanceof CjAnuncioPlano ? (string)$plano->nome : 'Anúncios';

		$cob = $pix->criarCobrancaPix([
			'valor'                => $fat->valor,
			'descricao'            => 'Conecta Jovem — '.$planoNome.' '.$fat->competencia.' — '.$nome,
			'vencimento'           => $fat->vencimento,
			'external_reference'   => 'cj_anuncio:'.(int)$fat->id,
			'notification_url'     => MercadoPagoXd360Helper::webhookUrl(),
			'statement_descriptor' => 'CJ ANUNCIOS',
			'pagador_nome'         => $nome,
			'pagador_email'        => $email,
			'email_fallback'       => MercadoPagoXd360Helper::payerEmailFallback(),
		]);
		if (!is_array($cob) || empty($cob['id']) || empty($cob['copia_cola'])) {
			return false;
		}
		$fat->mp_payment_id = $cob['id'];
		$fat->pix_copia_cola = $cob['copia_cola'];
		if (!empty($cob['qr_base64'])) {
			$fat->pix_qr_base64 = (string)$cob['qr_base64'];
		}
		$fat->atualizar();
		return true;
	}

	/** @return array<string,mixed> */
	public static function processar(?int $idEmpresaFiltro = null): array {
		$resumo = [
			'faturas_geradas' => 0,
			'expiradas'       => 0,
			'erros'           => [],
			'modulo_ok'       => self::moduloAtivo(),
			'mp_ok'           => MercadoPagoXd360Helper::configurado(),
		];
		if (!self::moduloAtivo()) {
			$resumo['erros'][] = 'Tabelas de assinatura ausentes.';
			return $resumo;
		}

		$hoje = date('Y-m-d');
		foreach (CjAnuncioAssinatura::listarParaWorker($idEmpresaFiltro) as $ass) {
			if (($ass->status ?? '') !== 'ativa') {
				continue;
			}
			$prox = (string)($ass->proximo_vencimento ?? '');
			if ($prox === '' || $prox > $hoje) {
				continue;
			}

			$competencia = date('Y-m');
			$existente = CjAnuncioFatura::getPorEmpresaCompetencia((int)$ass->id_empresa, $competencia);
			if (!$existente instanceof CjAnuncioFatura) {
				$plano = CjAnuncioPlano::getById((int)$ass->plan_id);
				if (!$plano instanceof CjAnuncioPlano) {
					continue;
				}
				$fat = new CjAnuncioFatura();
				$fat->id_assinatura = (int)$ass->id;
				$fat->id_empresa = (int)$ass->id_empresa;
				$fat->plan_id = (int)$ass->plan_id;
				$fat->competencia = $competencia;
				$fat->valor = (float)$plano->valor_mensal;
				$fat->vencimento = date('Y-m-d', strtotime('+5 days'));
				$fat->status = 'aberta';
				if ($fat->cadastrar()) {
					$empresa = CjEmpresa::getById((int)$ass->id_empresa);
					self::anexarPix($fat, $empresa, $plano);
					$resumo['faturas_geradas']++;
				}
			}

			$graceLimite = date('Y-m-d', strtotime($prox.' +'.self::GRACE_DIAS.' days'));
			if ($hoje > $graceLimite) {
				$aberta = CjAnuncioFatura::getAbertaPorAssinatura((int)$ass->id);
				if ($aberta instanceof CjAnuncioFatura) {
					$ass->status = 'expirada';
					$ass->fim_em = date('Y-m-d H:i:s');
					$ass->atualizar();
					$resumo['expiradas']++;
				}
			}
		}

		return $resumo;
	}

	/** @return array{ok:bool,message?:string} */
	public static function marcarPagaMaster(int $faturaId): array {
		if (!self::moduloAtivo()) {
			return ['ok' => false, 'message' => 'Módulo não instalado.'];
		}
		$fat = CjAnuncioFatura::getById($faturaId);
		if (!$fat instanceof CjAnuncioFatura) {
			return ['ok' => false, 'message' => 'Fatura não encontrada.'];
		}
		if (($fat->status ?? '') === 'pago') {
			return ['ok' => true, 'message' => 'Fatura já estava paga.'];
		}
		self::marcarPaga($fat);
		return ['ok' => true, 'message' => 'Pagamento registrado manualmente.'];
	}

	/** @param array<string,mixed>|null $plano */
	private static function formatarAssinatura(CjAnuncioAssinatura $ass, ?array $plano = null): array {
		return [
			'id'                    => (int)$ass->id,
			'status'                => (string)($ass->status ?? ''),
			'inicioEm'              => $ass->inicio_em,
			'inicioEmBr'            => self::formatarDataBr($ass->inicio_em, true),
			'fimEm'                 => $ass->fim_em,
			'fimEmBr'               => self::formatarDataBr($ass->fim_em, true),
			'proximoVencimento'     => $ass->proximo_vencimento,
			'proximoVencimentoBr'   => self::formatarDataBr($ass->proximo_vencimento),
			'canceladaEm'           => $ass->cancelada_em,
			'canceladaEmBr'         => self::formatarDataBr($ass->cancelada_em, true),
			'plano'                 => $plano,
		];
	}

	private static function formatarFatura(CjAnuncioFatura $fat): array {
		return [
			'id'              => (int)$fat->id,
			'competencia'     => (string)$fat->competencia,
			'competenciaBr'   => self::formatarCompetenciaBr((string)$fat->competencia),
			'valor'           => round((float)$fat->valor, 2),
			'vencimento'      => (string)$fat->vencimento,
			'vencimentoBr'    => self::formatarDataBr($fat->vencimento),
			'status'          => (string)$fat->status,
			'pixCopiaCola'    => (string)($fat->pix_copia_cola ?? ''),
			'pixQrBase64'     => (string)($fat->pix_qr_base64 ?? ''),
			'pagoEm'          => $fat->pago_em,
			'pagoEmBr'        => self::formatarDataBr($fat->pago_em, true),
		];
	}

	private static function formatarDataBr($data, bool $comHora = false): ?string {
		$s = trim((string)($data ?? ''));
		if ($s === '' || str_starts_with($s, '0000-00-00')) {
			return null;
		}
		$ts = strtotime($s);
		if ($ts === false) {
			return $s;
		}
		return $comHora ? date('d/m/Y H:i', $ts) : date('d/m/Y', $ts);
	}

	private static function formatarCompetenciaBr(string $competencia): string {
		if (!preg_match('/^(\d{4})-(\d{2})$/', $competencia, $m)) {
			return $competencia;
		}
		$meses = [
			'01' => 'janeiro', '02' => 'fevereiro', '03' => 'março', '04' => 'abril',
			'05' => 'maio', '06' => 'junho', '07' => 'julho', '08' => 'agosto',
			'09' => 'setembro', '10' => 'outubro', '11' => 'novembro', '12' => 'dezembro',
		];
		$mes = $meses[$m[2]] ?? $m[2];
		return ucfirst($mes).' de '.$m[1];
	}
}
