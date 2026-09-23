<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class SocialWorkerRun {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'social_worker_runs'");
			$ok = $st && $st->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function registrar(string $origem, int $idAdmin, array $resumo): void {
		if (!self::tabelaExiste()) {
			return;
		}
		try {
			(new Database('social_worker_runs'))->insert([
				'origem' => mb_substr($origem, 0, 20),
				'id_admin' => $idAdmin,
				'processados' => (int)($resumo['processados'] ?? 0),
				'ok' => (int)($resumo['ok'] ?? 0),
				'erro' => (int)($resumo['erro'] ?? 0),
				'detalhe' => isset($resumo['detalhe']) ? mb_substr((string)$resumo['detalhe'], 0, 500) : null,
			]);
		} catch (\Throwable $e) {
			// ignore
		}
	}

	/** @return array|null */
	public static function ultima(int $idAdmin = 0): ?array {
		if (!self::tabelaExiste()) {
			return null;
		}
		$where = '1=1';
		if ($idAdmin > 0) {
			$where = '(id_admin = 0 OR id_admin = '.(int)$idAdmin.')';
		}
		$row = (new Database('social_worker_runs'))->select($where, 'id DESC', 1)->fetch(\PDO::FETCH_ASSOC);
		return is_array($row) ? $row : null;
	}
}
