<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class LmsVitrineConfig {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_vitrine_config'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function taxaCtiMensal(): float {
		if (!self::tabelaExiste()) {
			return 0.0;
		}
		$row = (new Database('lms_vitrine_config'))->select('id = 1', null, 1)->fetch(\PDO::FETCH_ASSOC);
		return $row ? (float)($row['taxa_cti_mensal'] ?? 0) : 0.0;
	}

	public static function setTaxaCtiMensal(float $valor): bool {
		if (!self::tabelaExiste()) {
			return false;
		}
		$valor = max(0, round($valor, 2));
		$db = new Database('lms_vitrine_config');
		$exist = $db->select('id = 1', null, 1)->fetch(\PDO::FETCH_ASSOC);
		if ($exist) {
			$db->update('id = 1', ['taxa_cti_mensal' => $valor]);
		} else {
			$db->insert(['id' => 1, 'taxa_cti_mensal' => $valor]);
		}
		return true;
	}
}
