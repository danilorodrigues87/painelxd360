<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class FinanceiroAcordo {

	public $id;
	public $id_admin;
	public $id_aluno;
	public $valor_total = 0;
	public $valor_entrada = 0;
	public $valor_parcela = 0;
	public $qtd_parcelas = 1;
	public $dia_vencimento = 10;
	public $primeiro_vencimento;
	public $observacao;
	public $ids_titulos_origem;
	public $status = 'ativo';
	public $id_usuario;
	public $created_at;

	private static $cacheTabela = null;
	private static $cacheColunaCaixa = null;
	private static $cacheColunaValorEntrada = null;

	public static function tabelasExistem(): bool {
		if (self::$cacheTabela !== null) {
			return self::$cacheTabela;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'financeiro_acordos'");
			self::$cacheTabela = (bool)$stmt->fetch();
		} catch (\Throwable $e) {
			self::$cacheTabela = false;
		}
		return self::$cacheTabela;
	}

	public static function temValorEntrada(): bool {
		if (self::$cacheColunaValorEntrada !== null) {
			return self::$cacheColunaValorEntrada;
		}
		if (!self::tabelasExistem()) {
			self::$cacheColunaValorEntrada = false;
			return false;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW COLUMNS FROM financeiro_acordos LIKE 'valor_entrada'");
			self::$cacheColunaValorEntrada = (bool)$stmt->fetch();
		} catch (\Throwable $e) {
			self::$cacheColunaValorEntrada = false;
		}
		return self::$cacheColunaValorEntrada;
	}

	public static function caixaTemIdAcordo(): bool {
		if (self::$cacheColunaCaixa !== null) {
			return self::$cacheColunaCaixa;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW COLUMNS FROM caixa LIKE 'id_acordo'");
			self::$cacheColunaCaixa = (bool)$stmt->fetch();
		} catch (\Throwable $e) {
			self::$cacheColunaCaixa = false;
		}
		return self::$cacheColunaCaixa;
	}

	public static function getById(int $id) {
		if (!self::tabelasExistem() || $id <= 0) {
			return null;
		}
		return (new Database('financeiro_acordos'))
			->select('id = '.(int)$id)
			->fetchObject(self::class);
	}

	public static function listByAluno(int $idAluno, int $idAdmin): array {
		if (!self::tabelasExistem()) {
			return [];
		}
		$stmt = (new Database('financeiro_acordos'))->select(
			'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin,
			'id DESC'
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	public function cadastrar(): int {
		$data = [
			'id_admin' => (int)$this->id_admin,
			'id_aluno' => (int)$this->id_aluno,
			'valor_total' => (float)$this->valor_total,
			'valor_parcela' => (float)$this->valor_parcela,
			'qtd_parcelas' => (int)$this->qtd_parcelas,
			'dia_vencimento' => (int)$this->dia_vencimento,
			'primeiro_vencimento' => $this->primeiro_vencimento,
			'observacao' => $this->observacao !== null && $this->observacao !== '' ? (string)$this->observacao : null,
			'ids_titulos_origem' => $this->ids_titulos_origem,
			'status' => $this->status ?: 'ativo',
			'id_usuario' => $this->id_usuario !== null ? (int)$this->id_usuario : null,
		];
		if (self::temValorEntrada()) {
			$data['valor_entrada'] = round(max(0, (float)$this->valor_entrada), 2);
		}
		$this->id = (int)(new Database('financeiro_acordos'))->insert($data);
		return (int)$this->id;
	}
}
