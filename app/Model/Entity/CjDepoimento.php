<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjDepoimento {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_depoimentos'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarPublicos(int $limit = 12): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$limit = max(1, min(50, $limit));
		return self::queryLista(['ativo' => true, 'limit' => $limit]);
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarTodos(int $limit = 100): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		return self::queryLista(['limit' => $limit, 'status_any' => true]);
	}

	public static function getById(int $id): ?array {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$rows = self::queryLista(['id' => $id, 'status_any' => true, 'limit' => 1]);
		return $rows[0] ?? null;
	}

	/**
	 * @param array{id?:int,ativo?:bool,status_any?:bool,limit?:int} $filtros
	 * @return array<int,array<string,mixed>>
	 */
	private static function queryLista(array $filtros): array {
		$where = ['1=1'];
		if (empty($filtros['status_any'])) {
			$where[] = 'd.ativo = 1';
		}
		if (!empty($filtros['id'])) {
			$where[] = 'd.id = '.(int)$filtros['id'];
		}
		$limit = max(1, min(100, (int)($filtros['limit'] ?? 50)));
		$sql = 'SELECT d.*, cand.foto AS candidato_foto, cand.nome AS candidato_nome, '
			.'emp.logo AS empresa_logo, emp.nome_fantasia AS empresa_nome '
			.'FROM cj_depoimentos d '
			.'LEFT JOIN cj_candidatos cand ON cand.id = d.id_candidato '
			.'LEFT JOIN cj_empresas emp ON emp.id = d.id_empresa '
			.'WHERE '.implode(' AND ', $where).' '
			.'ORDER BY d.ordem ASC, d.id ASC LIMIT '.$limit;
		$stmt = (new Database())->execute($sql);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public static function inserir(array $dados): int {
		return (int)(new Database('cj_depoimentos'))->insert($dados);
	}

	public static function atualizar(int $id, array $dados): bool {
		return (new Database('cj_depoimentos'))->update('id = '.(int)$id, $dados);
	}

	public static function excluir(int $id): bool {
		return (new Database('cj_depoimentos'))->delete('id = '.(int)$id);
	}

	/** @return array<int,array<string,mixed>> */
	public static function buscarCandidatos(string $q, int $limit = 20): array {
		if (!CjCandidato::tabelaExiste()) {
			return [];
		}
		$q = addslashes(trim($q));
		$where = $q !== '' ? ' AND (c.nome LIKE "%'.$q.'%" OR c.email LIKE "%'.$q.'%")' : '';
		$limit = max(1, min(50, $limit));
		$stmt = (new Database())->execute(
			'SELECT c.id, c.nome, c.email, c.foto FROM cj_candidatos c '
			.'WHERE c.status = "ativo"'.$where.' ORDER BY c.nome ASC LIMIT '.$limit
		);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function buscarEmpresas(string $q, int $limit = 20): array {
		if (!CjEmpresa::tabelaExiste()) {
			return [];
		}
		$q = addslashes(trim($q));
		$where = $q !== '' ? ' AND (e.nome_fantasia LIKE "%'.$q.'%" OR e.razao_social LIKE "%'.$q.'%")' : '';
		$limit = max(1, min(50, $limit));
		$stmt = (new Database())->execute(
			'SELECT e.id, e.nome_fantasia, e.razao_social, e.logo FROM cj_empresas e '
			.'WHERE e.status = "aprovada"'.$where.' ORDER BY e.nome_fantasia ASC LIMIT '.$limit
		);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}
}
