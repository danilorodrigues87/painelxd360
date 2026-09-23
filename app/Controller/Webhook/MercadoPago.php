<?php

namespace App\Controller\Webhook;

use App\Common\Helpers\MercadoPagoEscolaHelper;
use App\Common\Helpers\MercadoPagoXd360Helper;
use App\Common\Helpers\SaasAssinaturaService;
use App\Common\Helpers\ConectAnuncioAssinaturaService;
use App\Common\Helpers\FinanceiroAlunoHelper;
use App\Common\Gateways\MercadoPago\Pix;
use App\Model\Entity\Caixa;
use App\Model\Entity\CjAnuncioFatura;
use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\SaasFatura;

class MercadoPago {

	/** Webhook conta CTI — faturas SaaS (escolas pagam a CTI). */
	public static function receberSaas($request, $token): string {
		if (!MercadoPagoXd360Helper::validarWebhookToken((string)$token)) {
			return json_encode(['success' => false, 'message' => 'Token inválido.']);
		}
		if (!MercadoPagoXd360Helper::validarAssinatura($request)) {
			return json_encode(['success' => false, 'message' => 'Assinatura inválida.']);
		}

		$raw = file_get_contents('php://input');
		$payload = json_decode((string)$raw, true);
		if (!is_array($payload)) {
			$post = $request->getPostVars();
			$payload = is_array($post) ? $post : [];
		}

		$query = $request->getQueryParams();
		$type = strtolower((string)($payload['type'] ?? $payload['topic'] ?? $query['topic'] ?? ''));
		$action = strtolower((string)($payload['action'] ?? ''));
		$dataId = (string)($payload['data']['id'] ?? $payload['id'] ?? $query['data.id'] ?? $query['id'] ?? '');

		if ($dataId === '' && isset($payload['resource'])) {
			$dataId = basename((string)$payload['resource']);
		}

		if ($type !== '' && $type !== 'payment' && strpos($type, 'payment') === false) {
			return json_encode(['success' => true, 'ignored' => true]);
		}

		if ($dataId === '' && preg_match('/payment/i', $action) !== 1) {
			return json_encode(['success' => true, 'ignored' => true, 'reason' => 'sem_id']);
		}

		$pix = MercadoPagoXd360Helper::pix();
		if (!$pix instanceof Pix) {
			return json_encode(['success' => false, 'message' => 'MP CTI não configurado.']);
		}

		$pagamento = $pix->consultarPagamento($dataId);
		if (!$pagamento || ($pagamento['status'] ?? '') !== 'approved') {
			return json_encode(['success' => true, 'status' => $pagamento['status'] ?? 'unknown']);
		}

		$ok = self::baixarFaturaSaas($pagamento);
		if (!$ok) {
			$ok = self::baixarFaturaCjAnuncio($pagamento);
		}
		return json_encode(['success' => true, 'baixado' => $ok]);
	}

	/** @param array{id:string,status:string,transaction_amount:float,external_reference:string,date_approved:?string} $pagamento */
	private static function baixarFaturaSaas(array $pagamento): bool {
		if (!SaasFatura::tabelaExiste()) {
			return false;
		}

		$paymentId = preg_replace('/\D/', '', (string)$pagamento['id']);
		$fat = $paymentId !== '' ? SaasFatura::getPorMpPaymentId($paymentId) : false;

		if (!$fat instanceof SaasFatura) {
			$ref = (string)($pagamento['external_reference'] ?? '');
			if (preg_match('/^saas:(\d+)$/', $ref, $m)) {
				$fat = SaasFatura::getById((int)$m[1]);
			}
		}

		if (!$fat instanceof SaasFatura) {
			return false;
		}
		if ($fat->status === 'pago') {
			return true;
		}

		$pagoEm = date('Y-m-d H:i:s');
		if (!empty($pagamento['date_approved'])) {
			try {
				$pagoEm = (new \DateTimeImmutable((string)$pagamento['date_approved']))->format('Y-m-d H:i:s');
			} catch (\Throwable $e) {
				// keep now
			}
		}

		if ($paymentId !== '') {
			$fat->mp_payment_id = $paymentId;
		}
		return SaasAssinaturaService::marcarPaga($fat, $pagoEm);
	}

	/** @param array{id:string,status:string,transaction_amount:float,external_reference:string,date_approved:?string} $pagamento */
	private static function baixarFaturaCjAnuncio(array $pagamento): bool {
		if (!CjAnuncioFatura::tabelaExiste()) {
			return false;
		}

		$paymentId = preg_replace('/\D/', '', (string)$pagamento['id']);
		$fat = $paymentId !== '' ? CjAnuncioFatura::getPorMpPaymentId($paymentId) : null;

		if (!$fat instanceof CjAnuncioFatura) {
			$ref = (string)($pagamento['external_reference'] ?? '');
			if (preg_match('/^cj_anuncio:(\d+)$/', $ref, $m)) {
				$fat = CjAnuncioFatura::getById((int)$m[1]);
			}
		}

		if (!$fat instanceof CjAnuncioFatura) {
			return false;
		}
		if (($fat->status ?? '') === 'pago') {
			return true;
		}

		$pagoEm = date('Y-m-d H:i:s');
		if (!empty($pagamento['date_approved'])) {
			try {
				$pagoEm = (new \DateTimeImmutable((string)$pagamento['date_approved']))->format('Y-m-d H:i:s');
			} catch (\Throwable $e) {
				// keep now
			}
		}

		if ($paymentId !== '') {
			$fat->mp_payment_id = $paymentId;
		}
		return ConectAnuncioAssinaturaService::marcarPaga($fat, $pagoEm);
	}

	public static function pingSaas($token): string {
		if (!MercadoPagoXd360Helper::validarWebhookToken((string)$token)) {
			return json_encode(['ok' => false, 'message' => 'Token inválido.']);
		}
		return json_encode([
			'ok'      => true,
			'service' => 'mercadopago-saas',
			'mp_ok'   => MercadoPagoXd360Helper::configurado(),
		]);
	}

	public static function receber($request, $idAdmin, $token): string {
		$idAdmin = (int)$idAdmin;
		if ($idAdmin <= 0 || !MercadoPagoEscolaHelper::validarWebhookToken($idAdmin, (string)$token)) {
			return json_encode(['success' => false, 'message' => 'Token inválido.']);
		}

		if (!MercadoPagoEscolaHelper::validarAssinaturaWebhook($idAdmin, $request)) {
			return json_encode(['success' => false, 'message' => 'Assinatura inválida.']);
		}

		$raw = file_get_contents('php://input');
		$payload = json_decode((string)$raw, true);
		if (!is_array($payload)) {
			$post = $request->getPostVars();
			$payload = is_array($post) ? $post : [];
		}

		$query = $request->getQueryParams();
		$type = strtolower((string)($payload['type'] ?? $payload['topic'] ?? $query['topic'] ?? ''));
		$action = strtolower((string)($payload['action'] ?? ''));
		$dataId = (string)($payload['data']['id'] ?? $payload['id'] ?? $query['data.id'] ?? $query['id'] ?? '');

		// Notificação antiga: topic=payment&id=...
		if ($dataId === '' && isset($payload['resource'])) {
			$dataId = basename((string)$payload['resource']);
		}

		if ($type !== '' && $type !== 'payment' && strpos($type, 'payment') === false) {
			return json_encode(['success' => true, 'ignored' => true]);
		}

		if ($dataId === '' && preg_match('/payment/i', $action) !== 1) {
			return json_encode(['success' => true, 'ignored' => true, 'reason' => 'sem_id']);
		}

		$pix = MercadoPagoEscolaHelper::pixDaEscola($idAdmin);
		if (!$pix instanceof Pix) {
			return json_encode(['success' => false, 'message' => 'Mercado Pago não configurado.']);
		}

		$pagamento = $pix->consultarPagamento($dataId);
		if (!$pagamento || ($pagamento['status'] ?? '') !== 'approved') {
			return json_encode(['success' => true, 'status' => $pagamento['status'] ?? 'unknown']);
		}

		$ok = self::baixarTitulo($idAdmin, $pagamento);
		return json_encode(['success' => true, 'baixado' => $ok]);
	}

	/** @param array{id:string,status:string,transaction_amount:float,external_reference:string,date_approved:?string} $pagamento */
	private static function baixarTitulo(int $idAdmin, array $pagamento): bool {
		$paymentId = preg_replace('/\D/', '', (string)$pagamento['id']);
		if ($paymentId === '') {
			return false;
		}

		$titulo = Caixa::getCaixa(
			"txt_id = '".$paymentId."' AND id_admin = ".(int)$idAdmin,
			null,
			'1'
		)->fetchObject(Caixa::class);

		if (!$titulo instanceof Caixa) {
			// fallback external_reference = id_admin:caixaId
			$ref = (string)($pagamento['external_reference'] ?? '');
			if (preg_match('/^(\d+):(\d+)$/', $ref, $m) && (int)$m[1] === $idAdmin) {
				$titulo = Caixa::getCaixa(
					'id = '.(int)$m[2].' AND id_admin = '.(int)$idAdmin,
					null,
					'1'
				)->fetchObject(Caixa::class);
			}
		}

		if (!$titulo instanceof Caixa) {
			return false;
		}

		if (FinanceiroAlunoHelper::tituloPago($titulo->status ?? null)) {
			return true;
		}

		$dataPagamento = date('Y-m-d');
		if (!empty($pagamento['date_approved'])) {
			try {
				$dataPagamento = (new \DateTimeImmutable((string)$pagamento['date_approved']))->format('Y-m-d');
			} catch (\Throwable $e) {
				// keep today
			}
		}

		$valorFace = round((float)($titulo->valor ?? 0), 2);
		$valorPago = round((float)($pagamento['transaction_amount'] ?? 0), 2);
		if ($valorPago <= 0) {
			error_log('[MP webhook] pagamento sem transaction_amount caixa_id='.(int)($titulo->id ?? 0).' id_admin='.$idAdmin);
			return false;
		}
		if (!FinanceiroAlunoHelper::valorCompativelComFace($valorPago, $valorFace)) {
			error_log('[MP webhook] valor divergente caixa_id='.(int)($titulo->id ?? 0)
				.' id_admin='.$idAdmin
				.' face='.$valorFace
				.' pago='.$valorPago);
			return false;
		}

		$titulo->id_admin = $idAdmin;
		$titulo->txt_id = $paymentId;
		$titulo->valor_pago = $valorPago;
		$titulo->data_pagamento = $dataPagamento;
		$titulo->ultima_alteracao = date('Y-m-d H:i:s');
		$titulo->baixaViaApi();

		return true;
	}

	public static function ping($idAdmin, $token): string {
		$idAdmin = (int)$idAdmin;
		if (!MercadoPagoEscolaHelper::validarWebhookToken($idAdmin, (string)$token)) {
			return json_encode(['ok' => false, 'message' => 'Token inválido.']);
		}
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		return json_encode([
			'ok'      => true,
			'service' => 'mercadopago',
			'mp_ativo'=> $cfg instanceof EscolaIntegracoes ? (int)$cfg->mp_ativo : 0,
		]);
	}
}
