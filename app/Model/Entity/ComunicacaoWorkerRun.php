<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class ComunicacaoWorkerRun {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'comunicacao_worker_runs'");
			$ok = $st && $st->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function registrar(string $tipo, string $origem, int $idAdmin, array $resumo): void {
		if (!self::tabelaExiste()) {
			return;
		}
		if (!in_array($tipo, ['cobranca', 'aniversario'], true)) {
			return;
		}
		try {
			$detalhe = null;
			if (!empty($resumo['erro'])) {
				$detalhe = mb_substr((string)$resumo['erro'], 0, 500);
			} elseif (!empty($resumo['detalhes']) && is_array($resumo['detalhes'])) {
				$detalhe = mb_substr(json_encode($resumo['detalhes'], JSON_UNESCAPED_UNICODE), 0, 500);
			}
			(new Database('comunicacao_worker_runs'))->insert([
				'tipo' => $tipo,
				'origem' => mb_substr($origem, 0, 20),
				'id_admin' => $idAdmin,
				'enviados' => (int)($resumo['enviados'] ?? 0),
				'erros' => (int)($resumo['erros'] ?? 0),
				'ignorados' => (int)($resumo['ignorados'] ?? 0),
				'escolas' => (int)($resumo['escolas'] ?? 0),
				'detalhe' => $detalhe,
			]);
		} catch (\Throwable $e) {
			// ignore
		}
	}

	/** @return array|null */
	public static function ultima(string $tipo, int $idAdmin = 0): ?array {
		if (!self::tabelaExiste()) {
			return null;
		}
		$where = 'tipo = "'.addslashes($tipo).'"';
		if ($idAdmin > 0) {
			$where .= ' AND (id_admin = 0 OR id_admin = '.(int)$idAdmin.')';
		}
		$row = (new Database('comunicacao_worker_runs'))->select($where, 'id DESC', 1)->fetch(\PDO::FETCH_ASSOC);
		return is_array($row) ? $row : null;
	}
}
