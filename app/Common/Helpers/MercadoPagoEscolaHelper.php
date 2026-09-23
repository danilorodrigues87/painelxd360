<?php

namespace App\Common\Helpers;

use App\Common\Gateways\MercadoPago\Client;
use App\Common\Gateways\MercadoPago\Pix;
use App\Model\Entity\EscolaIntegracoes;

class MercadoPagoEscolaHelper {

	public static function escolaTemPixAtivo(int $idAdmin): bool {
		if ($idAdmin <= 0 || !EscolaIntegracoes::temColunasMercadoPago()) {
			return false;
		}
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		return $cfg instanceof EscolaIntegracoes && $cfg->temMercadoPagoAtivo();
	}

	public static function clientDaEscola(int $idAdmin): ?Client {
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes || !(int)$cfg->mp_ativo) {
			return null;
		}
		$token = $cfg->getMpAccessTokenDescriptografado();
		if ($token === null || $token === '') {
			return null;
		}
		return new Client($token);
	}

	public static function pixDaEscola(int $idAdmin): ?Pix {
		$client = self::clientDaEscola($idAdmin);
		return $client ? new Pix($client) : null;
	}

	public static function webhookUrl(int $idAdmin): string {
		$token = '';
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if ($cfg instanceof EscolaIntegracoes) {
			$token = (string)($cfg->mp_webhook_token ?? '');
		}
		if ($token === '') {
			$token = '{token}';
		}
		$base = defined('URL') ? rtrim((string)URL, '/') : '';
		return $base.'/webhook/mercadopago/'.$idAdmin.'/'.$token;
	}

	public static function validarWebhookToken(int $idAdmin, string $token): bool {
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			return false;
		}
		$esperado = (string)($cfg->mp_webhook_token ?? '');
		return $esperado !== '' && hash_equals($esperado, $token);
	}

	/**
	 * Valida x-signature do Mercado Pago quando o secret estiver configurado.
	 * Sem secret salvo, aceita (token da URL já autentica a escola).
	 */
	public static function validarAssinaturaWebhook(int $idAdmin, $request): bool {
		$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$cfg instanceof EscolaIntegracoes) {
			return false;
		}
		$secret = $cfg->getMpWebhookSecretDescriptografado();
		if ($secret === null || $secret === '') {
			return true;
		}

		$headers = self::headersNormalizados($request->getHeaders());
		$xSignature = (string)($headers['x-signature'] ?? '');
		$xRequestId = (string)($headers['x-request-id'] ?? '');
		if ($xSignature === '') {
			return false;
		}

		$ts = '';
		$v1 = '';
		foreach (explode(',', $xSignature) as $part) {
			$part = trim($part);
			if (strpos($part, '=') === false) {
				continue;
			}
			[$k, $v] = explode('=', $part, 2);
			$k = strtolower(trim($k));
			if ($k === 'ts') {
				$ts = trim($v);
			} elseif ($k === 'v1') {
				$v1 = trim($v);
			}
		}
		if ($ts === '' || $v1 === '') {
			return false;
		}

		$query = $request->getQueryParams();
		$dataId = (string)($query['data.id'] ?? $query['data_id'] ?? '');
		if ($dataId === '') {
			$post = $request->getPostVars();
			if (is_array($post)) {
				$dataId = (string)($post['data']['id'] ?? $post['id'] ?? '');
			}
		}
		if ($dataId !== '' && preg_match('/[a-zA-Z]/', $dataId)) {
			$dataId = strtolower($dataId);
		}

		$manifest = '';
		if ($dataId !== '') {
			$manifest .= 'id:'.$dataId.';';
		}
		if ($xRequestId !== '') {
			$manifest .= 'request-id:'.$xRequestId.';';
		}
		$manifest .= 'ts:'.$ts.';';

		$calc = hash_hmac('sha256', $manifest, $secret);
		return hash_equals($calc, $v1);
	}

	/** @param array<string,mixed> $headers */
	private static function headersNormalizados(array $headers): array {
		$out = [];
		foreach ($headers as $k => $v) {
			$out[strtolower((string)$k)] = $v;
		}
		return $out;
	}
}
