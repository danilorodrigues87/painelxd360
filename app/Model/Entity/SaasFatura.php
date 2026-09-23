<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class SaasFatura {

	public $id;
	public $id_admin;
	public $plan_id;
	public $competencia;
	public $valor;
	public $vencimento;
	public $status = 'aberta';
	public $mp_payment_id;
	public $pix_copia_cola;
	public $pix_qr_base64;
	public $email_enviado_em;
	public $pago_em;
	public $criado_em;
	public $atualizado_em;

	public static function temColunaPixQrBase64(): bool {
		return self::temColuna('pix_qr_base64');
	}

	public static function temColunaEmailEnviado(): bool {
		return self::temColuna('email_enviado_em');
	}

	private static function temColuna(string $coluna): bool {
		static $cache = [];
		$coluna = preg_replace('/[^a-z0-9_]/i', '', $coluna) ?: '';
		if ($coluna === '') {
			return false;
		}
		if (array_key_exists($coluna, $cache)) {
			return $cache[$coluna];
		}
		try {
			$row = (new Database('saas_faturas'))->execute(
				"SHOW COLUMNS FROM saas_faturas LIKE '{$coluna}'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache[$coluna] = !empty($row);
		} catch (\Throwable $e) {
			$cache[$coluna] = false;
		}
		return $cache[$coluna];
	}

	public static function tabelaExiste(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('saas_faturas'))->execute(
				"SHOW TABLES LIKE 'saas_faturas'"
			)->fetch(\PDO::FETCH_NUM);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function getById(int $id) {
		if ($id <= 0 || !self::tabelaExiste()) {
			return false;
		}
		return self::get('id = '.$id)->fetchObject(self::class);
	}

	public static function get($where = null, $order = null, $limit = null, $fields = '*') {
		return (new Database('saas_faturas'))->select($where, $order, $limit, $fields);
	}

	public function cadastrar(): bool {
		$dados = [
			'id_admin'       => (int)$this->id_admin,
			'plan_id'        => $this->plan_id !== null && $this->plan_id !== '' ? (int)$this->plan_id : null,
			'competencia'    => $this->competencia,
			'valor'          => round((float)$this->valor, 2),
			'vencimento'     => $this->vencimento,
			'status'         => $this->status ?: 'aberta',
			'mp_payment_id'  => $this->mp_payment_id ?: null,
			'pix_copia_cola' => $this->pix_copia_cola ?: null,
			'pago_em'        => $this->pago_em ?: null,
		];
		if (self::temColunaPixQrBase64()) {
			$dados['pix_qr_base64'] = $this->pix_qr_base64 ?: null;
		}
		if (self::temColunaEmailEnviado()) {
			$dados['email_enviado_em'] = $this->email_enviado_em ?: null;
		}
		$this->id = (int)(new Database('saas_faturas'))->insert($dados);
		return $this->id > 0;
	}

	public function atualizar(): bool {
		$dados = [
			'plan_id'        => $this->plan_id !== null && $this->plan_id !== '' ? (int)$this->plan_id : null,
			'valor'          => round((float)$this->valor, 2),
			'vencimento'     => $this->vencimento,
			'status'         => $this->status,
			'mp_payment_id'  => $this->mp_payment_id ?: null,
			'pix_copia_cola' => $this->pix_copia_cola ?: null,
			'pago_em'        => $this->pago_em ?: null,
		];
		if (self::temColunaPixQrBase64()) {
			$dados['pix_qr_base64'] = $this->pix_qr_base64 ?: null;
		}
		if (self::temColunaEmailEnviado()) {
			$dados['email_enviado_em'] = $this->email_enviado_em ?: null;
		}
		return (bool)(new Database('saas_faturas'))->update('id = '.(int)$this->id, $dados);
	}

	public static function getPorEscolaCompetencia(int $idAdmin, string $competencia) {
		return self::get(
			'id_admin = '.(int)$idAdmin.' AND competencia = "'.addslashes($competencia).'"',
			null,
			'1'
		)->fetchObject(self::class);
	}

	public static function getPorMpPaymentId(string $paymentId) {
		$paymentId = preg_replace('/\D/', '', $paymentId);
		if ($paymentId === '') {
			return false;
		}
		return self::get(
			"mp_payment_id = '".addslashes($paymentId)."'",
			null,
			'1'
		)->fetchObject(self::class);
	}
}
