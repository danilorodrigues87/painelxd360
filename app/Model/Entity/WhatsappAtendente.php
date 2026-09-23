<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class WhatsappAtendente {

	public $id;
	public $id_admin;
	public $usuario_id;
	public $setor_id;
	public $ativo = 1;

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
			$stmt = $pdo->query("SHOW TABLES LIKE 'whatsapp_atendentes'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function listarPorEscola(int $idAdmin): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$sql = 'SELECT a.*, u.nome AS usuario_nome, u.nivel AS usuario_nivel, s.nome AS setor_nome
			FROM whatsapp_atendentes a
			INNER JOIN usuarios u ON u.id = a.usuario_id
			INNER JOIN whatsapp_setores s ON s.id = a.setor_id
			WHERE a.id_admin = '.(int)$idAdmin.'
			ORDER BY s.ordem ASC, u.nome ASC';
		return (new Database('whatsapp_atendentes'))->execute($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	/** Atendentes ativos de um setor (únicos por usuário). */
	public static function listarPorSetor(int $idAdmin, int $setorId): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$sql = 'SELECT a.usuario_id, u.nome AS usuario_nome
			FROM whatsapp_atendentes a
			INNER JOIN usuarios u ON u.id = a.usuario_id
			WHERE a.id_admin = '.(int)$idAdmin.'
			  AND a.setor_id = '.(int)$setorId.'
			  AND a.ativo = 1
			GROUP BY a.usuario_id, u.nome
			ORDER BY u.nome ASC';
		return (new Database('whatsapp_atendentes'))->execute($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	public static function usuarioNoSetor(int $idAdmin, int $usuarioId, int $setorId): bool {
		if (!self::tabelaExiste()) {
			return false;
		}
		$row = (new Database('whatsapp_atendentes'))
			->select(
				'id_admin = '.(int)$idAdmin
				.' AND usuario_id = '.(int)$usuarioId
				.' AND setor_id = '.(int)$setorId
				.' AND ativo = 1',
				null,
				1,
				'id'
			)
			->fetch(\PDO::FETCH_ASSOC);
		return !empty($row['id']);
	}

	/** IDs de setor em que o usuário atende. */
	public static function setoresDoUsuario(int $idAdmin, int $usuarioId): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$rows = (new Database('whatsapp_atendentes'))
			->select(
				'id_admin = '.(int)$idAdmin.' AND usuario_id = '.(int)$usuarioId.' AND ativo = 1',
				null,
				null,
				'setor_id'
			)
			->fetchAll(\PDO::FETCH_ASSOC);
		return array_map('intval', array_column($rows ?: [], 'setor_id'));
	}

	public static function vincular(int $idAdmin, int $usuarioId, int $setorId): bool {
		if (!self::tabelaExiste()) {
			return false;
		}
		$existe = (new Database('whatsapp_atendentes'))
			->select(
				'id_admin = '.(int)$idAdmin.' AND usuario_id = '.(int)$usuarioId.' AND setor_id = '.(int)$setorId,
				null,
				1,
				'id'
			)
			->fetch(\PDO::FETCH_ASSOC);
		if (!empty($existe['id'])) {
			(new Database('whatsapp_atendentes'))->update(
				'id = '.(int)$existe['id'],
				['ativo' => 1]
			);
			return true;
		}
		(new Database('whatsapp_atendentes'))->insert([
			'id_admin'   => $idAdmin,
			'usuario_id' => $usuarioId,
			'setor_id'   => $setorId,
			'ativo'      => 1,
		]);
		return true;
	}

	public static function desvincular(int $idAdmin, int $id): bool {
		if (!self::tabelaExiste()) {
			return false;
		}
		(new Database('whatsapp_atendentes'))->delete(
			'id = '.(int)$id.' AND id_admin = '.(int)$idAdmin
		);
		return true;
	}
}
