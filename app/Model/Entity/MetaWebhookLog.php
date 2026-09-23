<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class MetaWebhookLog {

	public static function tabelaExiste(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW TABLES LIKE 'meta_webhook_log'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function registrar(
		?int $idAdmin,
		string $tipo,
		string $status,
		?string $evento = null,
		?string $metaMessageId = null,
		?string $resumo = null,
		?string $detalhe = null
	): void {
		if (!self::tabelaExiste()) {
			return;
		}
		try {
			(new Database('meta_webhook_log'))->insert([
				'id_admin'        => $idAdmin && $idAdmin > 0 ? $idAdmin : null,
				'tipo'            => $tipo,
				'evento'          => $evento,
				'meta_message_id' => $metaMessageId,
				'payload_resumo'  => $resumo !== null ? mb_substr($resumo, 0, 500) : null,
				'status'          => $status,
				'detalhe'         => $detalhe !== null ? mb_substr($detalhe, 0, 65000) : null,
			]);
		} catch (\Throwable $e) {
			// não interrompe webhook
		}
	}

	/** @return array<int,array<string,mixed>> */
	public static function listRecentes(?int $idAdmin, string $tipo, int $limite = 30): array {
		return self::listRecentesTipos($idAdmin, [$tipo], $limite);
	}

	/** @param array<int,string> $tipos */
	public static function listRecentesTipos(?int $idAdmin, array $tipos, int $limite = 30): array {
		if (!self::tabelaExiste() || !$tipos) {
			return [];
		}
		$limite = max(1, min(100, $limite));
		$tiposSql = array_map(static function (string $t): string {
			return '"'.addslashes($t).'"';
		}, $tipos);
		$where = 'tipo IN ('.implode(',', $tiposSql).')';
		if ($idAdmin && $idAdmin > 0) {
			$where .= ' AND (id_admin = '.(int)$idAdmin.' OR id_admin IS NULL)';
		}
		$sql = 'SELECT * FROM meta_webhook_log WHERE '.$where
			.' ORDER BY id DESC LIMIT '.$limite;
		return (new Database('meta_webhook_log'))->execute($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}
}
