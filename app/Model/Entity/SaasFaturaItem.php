<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class SaasFaturaItem {

	public $id;
	public $id_fatura;
	public $tipo;
	public $descricao;
	public $valor = 0;
	public $id_curso;
	public $id_vitrine_assinatura;
	public $id_escola_criadora;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'saas_fatura_itens'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public function cadastrar(): int {
		$this->id = (int)(new Database('saas_fatura_itens'))->insert([
			'id_fatura' => (int)$this->id_fatura,
			'tipo' => $this->tipo,
			'descricao' => $this->descricao,
			'valor' => round((float)$this->valor, 2),
			'id_curso' => $this->id_curso ? (int)$this->id_curso : null,
			'id_vitrine_assinatura' => $this->id_vitrine_assinatura ? (int)$this->id_vitrine_assinatura : null,
			'id_escola_criadora' => $this->id_escola_criadora ? (int)$this->id_escola_criadora : null,
		]);
		return $this->id;
	}

	public static function apagarPorFatura(int $idFatura): void {
		if (!self::tabelaExiste()) {
			return;
		}
		(new Database('saas_fatura_itens'))->delete('id_fatura = '.(int)$idFatura);
	}

	/** @return array<int,array> */
	public static function listByFatura(int $idFatura): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$stmt = (new Database('saas_fatura_itens'))->select('id_fatura = '.(int)$idFatura, 'id ASC');
		$out = [];
		while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$out[] = $r;
		}
		return $out;
	}
}
