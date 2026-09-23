<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class StaffNotificacao {

	public $id;
	public $id_admin;
	public $tipo = 'system';
	public $titulo;
	public $mensagem = '';
	public $link;
	public $ref_chave;
	public $created_at;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'staff_notificacoes'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function leiturasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'staff_notificacao_leituras'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function existeRef(int $idAdmin, string $ref): bool {
		if (!self::tabelaExiste() || $ref === '') {
			return false;
		}
		$row = (new Database('staff_notificacoes'))->select(
			'id_admin = '.(int)$idAdmin.' AND ref_chave = "'.addslashes($ref).'"',
			null,
			'1',
			'id'
		)->fetch(PDO::FETCH_ASSOC);
		return !empty($row);
	}

	public static function criar(array $dados): ?int {
		if (!self::tabelaExiste()) {
			return null;
		}
		$idAdmin = (int)($dados['id_admin'] ?? 0);
		if ($idAdmin <= 0) {
			return null;
		}
		$ref = trim((string)($dados['ref_chave'] ?? ''));
		if ($ref !== '' && self::existeRef($idAdmin, $ref)) {
			return null;
		}
		$db = new Database('staff_notificacoes');
		$id = $db->insert([
			'id_admin'  => $idAdmin,
			'tipo'      => (string)($dados['tipo'] ?? 'system'),
			'titulo'    => mb_substr((string)($dados['titulo'] ?? 'Notificação'), 0, 191),
			'mensagem'  => mb_substr((string)($dados['mensagem'] ?? ''), 0, 500),
			'link'      => ($dados['link'] ?? null) ? mb_substr((string)$dados['link'], 0, 500) : null,
			'ref_chave' => $ref !== '' ? mb_substr($ref, 0, 128) : null,
		]);
		return $id ? (int)$id : null;
	}

	/**
	 * @param array<int,string> $tiposPermitidos
	 * @return array<int,array<string,mixed>>
	 */
	public static function listarParaUsuario(int $idAdmin, int $idUsuario, array $tiposPermitidos, int $limit = 30): array {
		if (!self::tabelaExiste() || $idAdmin <= 0 || $idUsuario <= 0 || empty($tiposPermitidos)) {
			return [];
		}
		$tiposSql = [];
		foreach ($tiposPermitidos as $t) {
			$tiposSql[] = '"'.addslashes((string)$t).'"';
		}
		$whereTipos = 'n.tipo IN ('.implode(',', $tiposSql).')';
		$sql = 'SELECT n.*, '
			.'CASE WHEN l.id IS NULL THEN 0 ELSE 1 END AS lida '
			.'FROM staff_notificacoes n '
			.'LEFT JOIN staff_notificacao_leituras l ON l.notificacao_id = n.id AND l.id_usuario = '.(int)$idUsuario.' '
			.'WHERE n.id_admin = '.(int)$idAdmin.' AND '.$whereTipos.' '
			.'ORDER BY n.created_at DESC, n.id DESC '
			.'LIMIT '.max(1, min(100, $limit));

		try {
			$db = new Database();
			$stmt = $db->execute($sql);
		} catch (\Throwable $e) {
			return [];
		}
		$out = [];
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$out[] = [
				'id'         => (int)($row['id'] ?? 0),
				'tipo'       => (string)($row['tipo'] ?? ''),
				'titulo'     => (string)($row['titulo'] ?? ''),
				'mensagem'   => (string)($row['mensagem'] ?? ''),
				'link'       => (string)($row['link'] ?? ''),
				'lida'       => !empty($row['lida']),
				'created_at' => (string)($row['created_at'] ?? ''),
			];
		}
		return $out;
	}

	/**
	 * @param array<int,string> $tiposPermitidos
	 */
	public static function contarNaoLidas(int $idAdmin, int $idUsuario, array $tiposPermitidos): int {
		if (!self::tabelaExiste() || !self::leiturasExistem() || empty($tiposPermitidos)) {
			return 0;
		}
		$tiposSql = [];
		foreach ($tiposPermitidos as $t) {
			$tiposSql[] = '"'.addslashes((string)$t).'"';
		}
		$sql = 'SELECT COUNT(*) AS c FROM staff_notificacoes n '
			.'LEFT JOIN staff_notificacao_leituras l ON l.notificacao_id = n.id AND l.id_usuario = '.(int)$idUsuario.' '
			.'WHERE n.id_admin = '.(int)$idAdmin.' AND n.tipo IN ('.implode(',', $tiposSql).') AND l.id IS NULL';
		try {
			$db = new Database();
			$row = $db->execute($sql)->fetch(PDO::FETCH_ASSOC);
			return (int)($row['c'] ?? 0);
		} catch (\Throwable $e) {
			return 0;
		}
	}

	public static function marcarLida(int $notificacaoId, int $idUsuario, int $idAdmin): bool {
		if (!self::tabelaExiste() || !self::leiturasExistem()) {
			return false;
		}
		$row = (new Database('staff_notificacoes'))->select(
			'id = '.(int)$notificacaoId.' AND id_admin = '.(int)$idAdmin,
			null,
			'1',
			'id'
		)->fetch(PDO::FETCH_ASSOC);
		if (empty($row)) {
			return false;
		}
		$db = new Database('staff_notificacao_leituras');
		$ex = $db->select(
			'notificacao_id = '.(int)$notificacaoId.' AND id_usuario = '.(int)$idUsuario,
			null,
			'1',
			'id'
		)->fetch(PDO::FETCH_ASSOC);
		if (!empty($ex)) {
			return true;
		}
		return (bool)$db->insert([
			'notificacao_id' => (int)$notificacaoId,
			'id_usuario'     => (int)$idUsuario,
		]);
	}

	/**
	 * @param array<int,string> $tiposPermitidos
	 */
	public static function marcarTodasLidas(int $idAdmin, int $idUsuario, array $tiposPermitidos): void {
		if (!self::tabelaExiste() || !self::leiturasExistem() || empty($tiposPermitidos)) {
			return;
		}
		$lista = self::listarParaUsuario($idAdmin, $idUsuario, $tiposPermitidos, 200);
		foreach ($lista as $item) {
			if (empty($item['lida'])) {
				self::marcarLida((int)$item['id'], $idUsuario, $idAdmin);
			}
		}
	}
}
