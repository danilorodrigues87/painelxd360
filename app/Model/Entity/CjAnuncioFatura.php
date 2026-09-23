<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class CjAnuncioFatura {

	public $id;
	public $id_assinatura;
	public $id_empresa;
	public $plan_id;
	public $competencia;
	public $valor;
	public $vencimento;
	public $status = 'aberta';
	public $mp_payment_id;
	public $pix_copia_cola;
	public $pix_qr_base64;
	public $pago_em;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_anuncio_faturas'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getById(int $id): ?self {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$row = (new Database('cj_anuncio_faturas'))->select('id = '.(int)$id, null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getPorEmpresaCompetencia(int $idEmpresa, string $competencia): ?self {
		if (!self::tabelaExiste() || $idEmpresa <= 0) {
			return null;
		}
		$row = (new Database('cj_anuncio_faturas'))->select(
			'id_empresa = '.(int)$idEmpresa.' AND competencia = "'.addslashes($competencia).'"',
			null,
			'1'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getAbertaPorAssinatura(int $idAssinatura): ?self {
		if (!self::tabelaExiste() || $idAssinatura <= 0) {
			return null;
		}
		$row = (new Database('cj_anuncio_faturas'))->select(
			'id_assinatura = '.(int)$idAssinatura.' AND status = "aberta"',
			'id DESC',
			'1'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getPorMpPaymentId(string $paymentId): ?self {
		$paymentId = preg_replace('/\D/', '', $paymentId);
		if (!self::tabelaExiste() || $paymentId === '') {
			return null;
		}
		$row = (new Database('cj_anuncio_faturas'))->select(
			"mp_payment_id = '".addslashes($paymentId)."'",
			null,
			'1'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public function cadastrar(): bool {
		$this->id = (int)(new Database('cj_anuncio_faturas'))->insert([
			'id_assinatura'  => (int)$this->id_assinatura,
			'id_empresa'     => (int)$this->id_empresa,
			'plan_id'        => (int)$this->plan_id,
			'competencia'    => $this->competencia,
			'valor'          => round((float)$this->valor, 2),
			'vencimento'     => $this->vencimento,
			'status'         => $this->status ?: 'aberta',
			'mp_payment_id'  => $this->mp_payment_id ?: null,
			'pix_copia_cola' => $this->pix_copia_cola ?: null,
			'pix_qr_base64'  => $this->pix_qr_base64 ?: null,
			'pago_em'        => $this->pago_em ?: null,
		]);
		return $this->id > 0;
	}

	public function atualizar(): bool {
		return (bool)(new Database('cj_anuncio_faturas'))->update('id = '.(int)$this->id, [
			'valor'          => round((float)$this->valor, 2),
			'vencimento'     => $this->vencimento,
			'status'         => $this->status,
			'mp_payment_id'  => $this->mp_payment_id ?: null,
			'pix_copia_cola' => $this->pix_copia_cola ?: null,
			'pix_qr_base64'  => $this->pix_qr_base64 ?: null,
			'pago_em'        => $this->pago_em ?: null,
		]);
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarMaster(int $limit = 200): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$sql = 'SELECT f.*, p.nome AS plano_nome, e.nome_fantasia AS empresa_nome, e.email AS empresa_email '
			.'FROM cj_anuncio_faturas f '
			.'LEFT JOIN cj_anuncio_planos p ON p.id = f.plan_id '
			.'LEFT JOIN cj_empresas e ON e.id = f.id_empresa '
			.'ORDER BY f.id DESC LIMIT '.max(1, min(500, $limit));
		$stmt = (new Database())->execute($sql);
		return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
	}
}
