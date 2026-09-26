<?php

namespace App\Common\Gateways\MercadoPago;

class Client {

	private string $accessToken;
	private string $baseUrl = 'https://api.mercadopago.com';

	public function __construct(string $accessToken) {
		$this->accessToken = trim($accessToken);
	}

	public function getAccessToken(): string {
		return $this->accessToken;
	}

	/**
	 * @return array{ok:bool,status:int,body:?array,raw:string,error:?string}
	 */
	public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array {
		if ($this->accessToken === '') {
			return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'error' => 'Access Token vazio.'];
		}

		$url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
		$headers = [
			'Authorization: Bearer '.$this->accessToken,
			'Content-Type: application/json',
			'Accept: application/json',
		];
		if ($idempotencyKey) {
			$headers[] = 'X-Idempotency-Key: '.$idempotencyKey;
		}

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => strtoupper($method),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 45,
		]);

		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
		}

		$raw = (string)curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr = curl_error($ch);
		curl_close($ch);

		if ($raw === '' && $curlErr !== '') {
			return ['ok' => false, 'status' => $status, 'body' => null, 'raw' => '', 'error' => $curlErr];
		}

		$decoded = json_decode($raw, true);
		$ok = $status >= 200 && $status < 300;

		return [
			'ok'     => $ok,
			'status' => $status,
			'body'   => is_array($decoded) ? $decoded : null,
			'raw'    => $raw,
			'error'  => $ok ? null : self::mensagemErro(is_array($decoded) ? $decoded : null, $status),
		];
	}

	private static function mensagemErro(?array $decoded, int $status): string {
		$msg = trim((string)($decoded['message'] ?? $decoded['error'] ?? ''));
		if ($msg === 'Invalid transaction_amount') {
			return 'O Mercado Pago recusou o valor. O boleto exige um valor com até 2 casas decimais e, em geral, no mínimo R$ 5,00.';
		}
		if ($msg === 'Invalid user identification number') {
			return 'O Mercado Pago recusou o CPF ou CNPJ. Confira o documento do cliente.';
		}
		$cause = '';
		if (isset($decoded['cause'][0]) && is_array($decoded['cause'][0])) {
			$cause = trim((string)($decoded['cause'][0]['description'] ?? $decoded['cause'][0]['code'] ?? ''));
		}
		if ($msg !== '' && $cause !== '') {
			return $msg.' — '.$cause;
		}
		if ($msg !== '') {
			return $msg;
		}
		return 'Erro HTTP '.$status;
	}

	/** @return array{ok:bool,message:string,user_id?:int} */
	public function testarConexao(): array {
		$res = $this->request('GET', '/users/me');
		if (!$res['ok']) {
			return ['ok' => false, 'message' => $res['error'] ?: 'Falha ao validar o Access Token.'];
		}
		$userId = (int)($res['body']['id'] ?? 0);
		return [
			'ok'      => true,
			'message' => 'Conexão OK'.($userId > 0 ? ' (conta #'.$userId.')' : ''),
			'user_id' => $userId,
		];
	}
}
