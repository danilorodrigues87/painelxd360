<?php

namespace App\Common\Gateways\MercadoPago;

use App\Common\Gateways\PixGatewayInterface;

class Pix implements PixGatewayInterface {

	private Client $client;
	private static ?string $ultimoErro = null;

	public function __construct(Client $client) {
		$this->client = $client;
	}

	public static function getUltimoErro(): ?string {
		return self::$ultimoErro;
	}

	public function criarCobrancaPix(array $dados): ?array {
		self::$ultimoErro = null;

		$valor = round((float)($dados['valor'] ?? 0), 2);
		if ($valor <= 0) {
			self::$ultimoErro = 'Valor inválido.';
			return null;
		}

		$descricao = mb_substr(trim((string)($dados['descricao'] ?? 'Mensalidade escolar')), 0, 200);
		if ($descricao === '') {
			$descricao = 'Mensalidade escolar';
		}

		$external = trim((string)($dados['external_reference'] ?? ''));
		$vencimento = (string)($dados['vencimento'] ?? '');
		$notificationUrl = trim((string)($dados['notification_url'] ?? ''));
		$statement = preg_replace('/[^A-Za-z0-9 ]/', '', (string)($dados['statement_descriptor'] ?? 'ESCOLA'));
		$statement = mb_substr(trim($statement) !== '' ? trim($statement) : 'ESCOLA', 0, 22);

		$email = self::emailValido($dados['pagador_email'] ?? null)
			?? self::emailValido($dados['email_fallback'] ?? null);
		if ($email === null) {
			self::$ultimoErro = 'E-mail do pagador inválido (obrigatório pelo Mercado Pago).';
			return null;
		}

		[$firstName, $lastName] = self::separarNome((string)($dados['pagador_nome'] ?? 'Pagador'));
		$cpf = preg_replace('/\D/', '', (string)($dados['pagador_cpf'] ?? ''));

		$payer = [
			'email'      => $email,
			'first_name' => $firstName,
			'last_name'  => $lastName,
		];
		if (strlen($cpf) === 11) {
			$payer['identification'] = [
				'type'   => 'CPF',
				'number' => $cpf,
			];
		}

		$endereco = self::montarEndereco($dados);
		if ($endereco !== null) {
			$payer['address'] = $endereco;
		}

		$body = [
			'transaction_amount'   => $valor,
			'description'          => $descricao,
			'payment_method_id'    => 'pix',
			'payer'                => $payer,
			'statement_descriptor' => $statement,
			'additional_info'      => [
				'items' => [[
					'id'          => $external !== '' ? mb_substr($external, 0, 64) : 'mensalidade',
					'title'       => mb_substr($descricao, 0, 127),
					'description' => $descricao,
					'category_id' => 'education_college',
					'quantity'    => 1,
					'unit_price'  => $valor,
				]],
				'payer' => [
					'first_name' => $firstName,
					'last_name'  => $lastName,
				],
			],
		];

		if ($external !== '') {
			$body['external_reference'] = mb_substr($external, 0, 256);
		}

		if ($notificationUrl !== '' && preg_match('#^https://#i', $notificationUrl)) {
			$body['notification_url'] = $notificationUrl;
		}

		$exp = self::formatarExpiracao($vencimento);
		if ($exp !== null) {
			$body['date_of_expiration'] = $exp;
		}

		$idempotency = $external !== ''
			? 'pix-'.$external.'-'.substr(hash('sha256', (string)$valor.$vencimento), 0, 16)
			: bin2hex(random_bytes(16));

		$res = $this->client->request('POST', '/v1/payments', $body, $idempotency);
		if (!$res['ok'] || !is_array($res['body'])) {
			self::$ultimoErro = $res['error'] ?: 'Falha ao criar PIX no Mercado Pago.';
			return null;
		}

		$id = (string)($res['body']['id'] ?? '');
		$qr = (string)($res['body']['point_of_interaction']['transaction_data']['qr_code'] ?? '');
		$qrB64 = $res['body']['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null;

		if ($id === '' || $qr === '') {
			self::$ultimoErro = 'Resposta do Mercado Pago sem QR Code.';
			return null;
		}

		return [
			'id'         => $id,
			'copia_cola' => $qr,
			'qr_base64'  => is_string($qrB64) && $qrB64 !== '' ? $qrB64 : null,
		];
	}

	/** @return array{id:string,status:string,transaction_amount:float,external_reference:string,date_approved:?string}|null */
	public function consultarPagamento(string $paymentId): ?array {
		$paymentId = preg_replace('/\D/', '', $paymentId);
		if ($paymentId === '') {
			return null;
		}
		$res = $this->client->request('GET', '/v1/payments/'.$paymentId);
		if (!$res['ok'] || !is_array($res['body'])) {
			return null;
		}
		return [
			'id'                  => (string)($res['body']['id'] ?? $paymentId),
			'status'              => (string)($res['body']['status'] ?? ''),
			'transaction_amount'  => (float)($res['body']['transaction_amount'] ?? 0),
			'external_reference'  => (string)($res['body']['external_reference'] ?? ''),
			'date_approved'       => $res['body']['date_approved'] ?? null,
		];
	}

	private static function emailValido($email): ?string {
		$email = trim((string)$email);
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return null;
		}
		// Domínios fictícios reduzem aprovação no antifraude
		if (preg_match('/@(pix\.local|example\.com|teste\.local)$/i', $email)) {
			return null;
		}
		return $email;
	}

	/** @return array{0:string,1:string} */
	private static function separarNome(string $nome): array {
		$nome = trim(preg_replace('/\s+/', ' ', $nome) ?? '');
		if ($nome === '') {
			return ['Pagador', 'Escola'];
		}
		$partes = explode(' ', $nome);
		$first = array_shift($partes);
		$last = trim(implode(' ', $partes));
		if ($last === '') {
			$last = 'Pagador';
		}
		return [mb_substr($first, 0, 60), mb_substr($last, 0, 60)];
	}

	private static function montarEndereco(array $dados): ?array {
		$rua = trim((string)($dados['pagador_endereco'] ?? ''));
		$cep = preg_replace('/\D/', '', (string)($dados['pagador_cep'] ?? ''));
		if ($rua === '' && $cep === '') {
			return null;
		}
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
		return $addr !== [] ? $addr : null;
	}

	private static function formatarExpiracao(string $vencimento): ?string {
		$vencimento = trim($vencimento);
		if ($vencimento === '') {
			return null;
		}
		try {
			$dt = new \DateTimeImmutable($vencimento.' 23:59:59', new \DateTimeZone('America/Sao_Paulo'));
			$agora = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
			if ($dt < $agora) {
				$dt = $agora->modify('+1 day');
			}
			return $dt->format('Y-m-d\TH:i:s.000P');
		} catch (\Throwable $e) {
			return null;
		}
	}
}
