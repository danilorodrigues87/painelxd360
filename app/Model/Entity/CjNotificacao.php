<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjNotificacao {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_notificacoes'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function inserir(array $dados): ?int {
		if (!self::tabelaExiste()) {
			return null;
		}
		$id = (new Database('cj_notificacoes'))->insert($dados);
		return $id ? (int)$id : null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarPorUsuario(int $idUsuario, int $limit = 50): array {
		if (!self::tabelaExiste() || $idUsuario <= 0) {
			return [];
		}
		$stmt = (new Database('cj_notificacoes'))->select(
			'id_usuario = '.(int)$idUsuario,
			'created_at DESC, id DESC',
			(string)max(1, min(100, $limit)),
			'*'
		);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public static function marcarLida(int $id, int $idUsuario): bool {
		if (!self::tabelaExiste() || $id <= 0 || $idUsuario <= 0) {
			return false;
		}
		return (new Database('cj_notificacoes'))->update(
			'id = '.(int)$id.' AND id_usuario = '.(int)$idUsuario.' AND lido_em IS NULL',
			['lido_em' => date('Y-m-d H:i:s')]
		);
	}
}
