<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjVaga {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_vagas'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getBySlug(string $slug): ?array {
		if (!self::tabelaExiste() || $slug === '') {
			return null;
		}
		$row = self::queryLista(['slug' => $slug, 'status' => 'publicada', 'limit' => 1]);
		return $row[0] ?? null;
	}

	public static function getById(int $id): ?array {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$stmt = (new Database())->execute(
			'SELECT v.*, e.nome_fantasia AS empresa_nome, e.logo AS empresa_logo, c.nome AS cidade_nome FROM cj_vagas v '
			.'LEFT JOIN cj_empresas e ON e.id = v.id_empresa '
			.'LEFT JOIN cidades c ON c.id = v.cidade_id WHERE v.id = '.(int)$id.' LIMIT 1'
		);
		$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
		return is_array($row) ? $row : null;
	}

	/**
	 * @param array{cidade_id?:int,empresa_id?:int,tipo_vaga?:string,slug?:string,status?:string,limit?:int} $filtros
	 * @return array<int,array<string,mixed>>
	 */
	public static function queryLista(array $filtros = []): array {
		if (!self::tabelaExiste()) {
			return [];
		}

		$where = [];
		if (!empty($filtros['empresa_id_internal'])) {
			$where[] = 'v.id_empresa = '.(int)$filtros['empresa_id_internal'];
			if (empty($filtros['status_any'])) {
				$where[] = 'v.status = "'.addslashes((string)($filtros['status'] ?? 'publicada')).'"';
			}
		} else {
			$where[] = 'v.status = "'.addslashes((string)($filtros['status'] ?? 'publicada')).'"';
			if (!empty($filtros['cidade_id'])) {
				$where[] = 'v.cidade_id = '.(int)$filtros['cidade_id'];
			}
			if (!empty($filtros['empresa_id'])) {
				$where[] = 'v.id_empresa = '.(int)$filtros['empresa_id'];
			}
		}

		if (!empty($filtros['tipo_vaga'])) {
			$where[] = 'v.tipo_vaga = "'.addslashes((string)$filtros['tipo_vaga']).'"';
		}
		if (!empty($filtros['q'])) {
			$q = addslashes(trim((string)$filtros['q']));
			$where[] = '(v.titulo LIKE "%'.$q.'%" OR v.descricao LIKE "%'.$q.'%" OR v.requisitos LIKE "%'.$q.'%" OR e.nome_fantasia LIKE "%'.$q.'%")';
		}
		if (!empty($filtros['slug'])) {
			$where[] = 'v.slug = "'.addslashes((string)$filtros['slug']).'"';
		}
		if (!empty($filtros['id'])) {
			$where[] = 'v.id = '.(int)$filtros['id'];
		}

		$joinEmpresa = !empty($filtros['empresa_id_internal'])
			? 'INNER JOIN cj_empresas e ON e.id = v.id_empresa '
			: 'INNER JOIN cj_empresas e ON e.id = v.id_empresa AND e.status = "aprovada" ';

		$limit = max(1, min(100, (int)($filtros['limit'] ?? 50)));
		$sql = 'SELECT v.*, e.nome_fantasia AS empresa_nome, e.logo AS empresa_logo, e.razao_social, c.nome AS cidade_nome '
			.'FROM cj_vagas v '
			.$joinEmpresa
			.'LEFT JOIN cidades c ON c.id = v.cidade_id '
			.'WHERE '.implode(' AND ', $where).' '
			.'ORDER BY v.updated_at DESC, v.id DESC '
			.'LIMIT '.$limit;
		$stmt = (new Database())->execute($sql);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public static function incrementarViews(int $id): void {
		if (!self::tabelaExiste() || $id <= 0) {
			return;
		}
		(new Database())->execute('UPDATE cj_vagas SET views_count = views_count + 1 WHERE id = '.(int)$id);
	}

	public static function cidadesComVagas(): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$sql = 'SELECT DISTINCT v.cidade_id AS id, c.nome '
			.'FROM cj_vagas v '
			.'INNER JOIN cidades c ON c.id = v.cidade_id '
			.'WHERE v.status = "publicada" AND v.cidade_id IS NOT NULL '
			.'ORDER BY c.nome ASC';
		$stmt = (new Database())->execute($sql);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public static function slugUnico(string $base, ?int $ignoreId = null): string {
		$slug = $base;
		$n = 0;
		while (true) {
			$where = 'slug = "'.addslashes($slug).'"';
			if ($ignoreId) {
				$where .= ' AND id != '.(int)$ignoreId;
			}
			$ex = (new Database('cj_vagas'))->select($where, null, '1', 'id')->fetch(PDO::FETCH_ASSOC);
			if (empty($ex)) {
				return $slug;
			}
			$n++;
			$slug = $base.'-'.$n;
		}
	}
}
