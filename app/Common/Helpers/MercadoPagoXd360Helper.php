<?php

namespace App\Common\Helpers;

use App\Common\Environment;
use App\Common\Gateways\MercadoPago\Client;
use App\Common\Gateways\MercadoPago\Pix;

/** Conta Mercado Pago do XD360 (Master cobra os clientes). Credenciais: MP_XD360_* no .env */
class MercadoPagoXd360Helper {

	public static function accessToken(): string {
		Environment::load(__DIR__.'/../../../');
		return trim((string)(
			Environment::get('MP_XD360_ACCESS_TOKEN')
			?: Environment::get('MP_ACCESS_TOKEN')
			?: ''
		));
	}

	public static function webhookSecret(): string {
		Environment::load(__DIR__.'/../../../');
		return trim((string)Environment::get('MP_XD360_WEBHOOK_SECRET', ''));
	}

	public static function webhookToken(): string {
		Environment::load(__DIR__.'/../../../');
		$token = trim((string)Environment::get('MP_XD360_WEBHOOK_TOKEN', ''));
		if ($token === '') {
			$token = hash('sha256', self::accessToken().'|saas');
		}
		return $token;
	}

	public static function configurado(): bool {
		return self::accessToken() !== '';
	}

	public static function payerEmailFallback(): string {
		Environment::load(__DIR__.'/../../../');
		$email = trim((string)(
			Environment::get('MP_XD360_PAYER_EMAIL')
			?: Environment::get('SMTP_FROM_EMAIL')
			?: ''
		));
		return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
	}

	public static function client(): ?Client {
		$token = self::accessToken();
		return $token !== '' ? new Client($token) : null;
	}

	public static function pix(): ?Pix {
		$client = self::client();
		return $client ? new Pix($client) : null;
	}

	public static function webhookUrl(): string {
		$base = defined('URL') ? rtrim((string)URL, '/') : '';
		return $base.'/webhook/mercadopago/saas/'.rawurlencode(self::webhookToken());
	}

	public static function validarWebhookToken(string $token): bool {
		$esperado = self::webhookToken();
		return $esperado !== '' && hash_equals($esperado, $token);
	}

	public static function validarAssinatura($request): bool {
		$secret = self::webhookSecret();
		if ($secret === '') {
			return true;
		}
		$headers = [];
		foreach ($request->getHeaders() as $k => $v) {
			$headers[strtolower((string)$k)] = $v;
		}
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
		$dataId = (string)($query['data.id'] ?? '');
		if ($dataId === '') {
			$post = $request->getPostVars();
			$dataId = is_array($post) ? (string)($post['data']['id'] ?? $post['id'] ?? '') : '';
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
		return hash_equals(hash_hmac('sha256', $manifest, $secret), $v1);
	}
}
