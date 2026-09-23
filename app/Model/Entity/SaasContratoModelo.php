<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class SaasContratoModelo {

	public $id = 1;
	public $html;
	public $atualizado_em;

	public static function tabelaExiste(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('saas_contrato_modelo'))->execute(
				"SHOW TABLES LIKE 'saas_contrato_modelo'"
			)->fetch(\PDO::FETCH_NUM);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function get(): ?self {
		if (!self::tabelaExiste()) {
			return null;
		}
		$row = (new Database('saas_contrato_modelo'))->select('id = 1', null, 1)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @param string|null $html NULL ou vazio = restaurar padrão (arquivo) */
	public static function salvar(?string $html): bool {
		if (!self::tabelaExiste()) {
			return false;
		}
		$db = new Database('saas_contrato_modelo');
		$exist = $db->select('id = 1', null, 1)->fetch(\PDO::FETCH_ASSOC);
		$valor = ($html !== null && trim($html) !== '') ? $html : null;
		if ($exist) {
			return (bool)$db->update('id = 1', ['html' => $valor]);
		}
		return (bool)$db->insert(['id' => 1, 'html' => $valor]);
	}
}
