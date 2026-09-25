<?php

namespace App\Common\Gateways\MercadoPago;

/**
 * Checkout Transparente da assinatura XD360: Pix, boleto e cartão.
 * O cartão chega só como token gerado no navegador.
 */
class Checkout {

	private Client $client;
	private Pix $pix;
	private static ?string $ultimoErro = null;

	public function __construct(Client $client) {
		$this->client = $client;
		$this->pix = new Pix($client);
	}

	public static function getUltimoErro(): ?string {
		return self::$ultimoErro ?: Pix::getUltimoErro();
	}

	public function criarPix(array $dados): ?array {
		self::$ultimoErro = null;
		$pix = $this->pix->criarCobrancaPix($dados);
		if ($pix === null) {
			self::$ultimoErro = Pix::getUltimoErro();
		}
		return $pix;
	}

	/** @return array{id:string,url:?string,linha:?string}|null */
	public function criarBoleto(array $dados): ?array {
		self::$ultimoErro = null;
		$valor = round((float)($dados['valor'] ?? 0), 2);
		if ($valor <= 0) {
			self::$ultimoErro = 'Valor inválido.';
			return null;
		}
		$doc = preg_replace('/\D/', '', (string)($dados['pagador_doc'] ?? $dados['pagador_cpf'] ?? ''));
		if (strlen($doc) !== 11 && strlen($doc) !== 14) {
			self::$ultimoErro = 'Boleto exige CPF ou CNPJ do cliente.';
			return null;
		}
		$email = trim((string)($dados['pagador_email'] ?? ''));
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			self::$ultimoErro = 'E-mail do pagador inválido.';
			return null;
		}
		$nome = trim((string)($dados['pagador_nome'] ?? 'Pagador'));
		$partes = preg_split('/\s+/', $nome) ?: [];
		$first = $partes[0] ?? 'Pagador';
		$last = trim(implode(' ', array_slice($partes, 1))) ?: 'Cliente';
		$payer = [
			'email'      => $email,
			'first_name' => mb_substr($first, 0, 60),
			'last_name'  => mb_substr($last, 0, 60),
			'identification' => [
				'type'   => strlen($doc) === 14 ? 'CNPJ' : 'CPF',
				'number' => $doc,
			],
		];
		$rua = trim((string)($dados['pagador_endereco'] ?? ''));
		$cep = preg_replace('/\D/', '', (string)($dados['pagador_cep'] ?? ''));
		if ($rua !== '' || strlen($cep) === 8) {
			$addr = [];
			if ($rua !== '') {
				$addr['street_name'] = mb_substr($rua, 0, 256);
			}
			$numero = trim((string)($dados['pagador_numero'] ?? ''));
			if ($numero !== '') {
				$addr['street_number'] = mb_substr($numero, 0, 20);
			}
			$bairro = trim((string)($dados['pagador_bairro'] ?? ''));
			if ($bairro !== '') {
				$addr['neighborhood'] = mb_substr($bairro, 0, 100);
			}
			$cidade = trim((string)($dados['pagador_cidade'] ?? ''));
			if ($cidade !== '') {
				$addr['city'] = mb_substr($cidade, 0, 100);
			}
			$uf = trim((string)($dados['pagador_uf'] ?? ''));
			if ($uf !== '') {
				$addr['federal_unit'] = mb_substr($uf, 0, 2);
			}
			if (strlen($cep) === 8) {
				$addr['zip_code'] = $cep;
			}
			if ($addr !== []) {
				$payer['address'] = $addr;
			}
		}

		$external = trim((string)($dados['external_reference'] ?? ''));
		$body = [
			'transaction_amount' => $valor,
			'description'        => mb_substr(trim((string)($dados['descricao'] ?? 'Assinatura XD360')), 0, 200),
			'payment_method_id'  => 'bolbradesco',
			'payer'              => $payer,
		];
		if ($external !== '') {
			$body['external_reference'] = mb_substr($external, 0, 256);
		}
		$notificationUrl = trim((string)($dados['notification_url'] ?? ''));
		if ($notificationUrl !== '' && preg_match('#^https://#i', $notificationUrl)) {
			$body['notification_url'] = $notificationUrl;
		}
		$venc = trim((string)($dados['vencimento'] ?? ''));
		if ($venc !== '') {
			try {
				$dt = new \DateTimeImmutable($venc.' 23:59:59', new \DateTimeZone('America/Sao_Paulo'));
				$body['date_of_expiration'] = $dt->format('Y-m-d\TH:i:s.000P');
			} catch (\Throwable $e) {
			}
		}
		$idempotency = 'bol-'.($external !== '' ? $external : bin2hex(random_bytes(8)));
		$res = $this->client->request('POST', '/v1/payments', $body, $idempotency);
		if (!$res['ok'] || !is_array($res['body'])) {
			self::$ultimoErro = $res['error'] ?: 'Falha ao criar boleto no Mercado Pago.';
			return null;
		}
		$id = (string)($res['body']['id'] ?? '');
		$url = (string)($res['body']['transaction_details']['external_resource_url'] ?? '');
		$linha = (string)($res['body']['barcode']['content'] ?? '');
		if ($id === '') {
			self::$ultimoErro = 'Resposta do Mercado Pago sem boleto.';
			return null;
		}
		return ['id' => $id, 'url' => $url !== '' ? $url : null, 'linha' => $linha !== '' ? $linha : null];
	}

	/** @return array{id:string,status:string,status_detail:string}|null */
	public function criarCartao(array $dados): ?array {
		self::$ultimoErro = null;
		$valor = round((float)($dados['valor'] ?? 0), 2);
		$token = trim((string)($dados['token'] ?? ''));
		$method = trim((string)($dados['payment_method_id'] ?? ''));
		if ($valor <= 0 || $token === '' || $method === '') {
			self::$ultimoErro = 'Dados do cartão incompletos.';
			return null;
		}
		$email = trim((string)($dados['pagador_email'] ?? ''));
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			self::$ultimoErro = 'E-mail do pagador inválido.';
			return null;
		}
		$doc = preg_replace('/\D/', '', (string)($dados['doc_number'] ?? ''));
		$docType = strtoupper(trim((string)($dados['doc_type'] ?? 'CPF')));
		$payer = ['email' => $email];
		if ($doc !== '') {
			$payer['identification'] = ['type' => $docType !== '' ? $docType : 'CPF', 'number' => $doc];
		}
		$external = trim((string)($dados['external_reference'] ?? ''));
		$body = [
			'transaction_amount' => $valor,
			'token'              => $token,
			'description'        => mb_substr(trim((string)($dados['descricao'] ?? 'Assinatura XD360')), 0, 200),
			'installments'       => 1,
			'payment_method_id'  => $method,
			'payer'              => $payer,
		];
		$issuer = trim((string)($dados['issuer_id'] ?? ''));
		if ($issuer !== '') {
			$body['issuer_id'] = $issuer;
		}
		if ($external !== '') {
			$body['external_reference'] = mb_substr($external, 0, 256);
		}
		$notificationUrl = trim((string)($dados['notification_url'] ?? ''));
		if ($notificationUrl !== '' && preg_match('#^https://#i', $notificationUrl)) {
			$body['notification_url'] = $notificationUrl;
		}
		$idempotency = 'card-'.substr(hash('sha256', $token.$external), 0, 32);
		$res = $this->client->request('POST', '/v1/payments', $body, $idempotency);
		if (!$res['ok'] || !is_array($res['body'])) {
			self::$ultimoErro = $res['error'] ?: 'Falha ao cobrar o cartão.';
			return null;
		}
		return [
			'id'            => (string)($res['body']['id'] ?? ''),
			'status'        => (string)($res['body']['status'] ?? ''),
			'status_detail' => (string)($res['body']['status_detail'] ?? ''),
		];
	}
}
