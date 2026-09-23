<?php

namespace App\Model\Entity;

use App\Common\Helpers\ConectApiMapper;
use App\Model\Db\Database;
use PDO;

class CjBlogPost {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_blog_posts'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getById(int $id): ?array {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$rows = self::queryLista(['id' => $id, 'status_any' => true, 'limit' => 1]);
		return $rows[0] ?? null;
	}

	public static function getBySlug(string $slug, bool $apenasPublicado = true): ?array {
		if (!self::tabelaExiste() || $slug === '') {
			return null;
		}
		$slug = trim(rawurldecode($slug));
		$candidatos = array_values(array_unique(array_filter([
			$slug,
			ConectApiMapper::slugify($slug),
		])));
		foreach ($candidatos as $try) {
			$filtros = ['slug' => $try, 'limit' => 1];
			if ($apenasPublicado) {
				$filtros['status'] = 'publicado';
			} else {
				$filtros['status_any'] = true;
			}
			$rows = self::queryLista($filtros);
			if (!empty($rows[0])) {
				return $rows[0];
			}
		}
		return null;
	}

	/**
	 * @param array{id?:int,slug?:string,status?:string,status_any?:bool,categoria_id?:int,categoria_slug?:string,q?:string,limit?:int,offset?:int} $filtros
	 * @return array<int,array<string,mixed>>
	 */
	public static function queryLista(array $filtros = []): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$where = ['1=1'];
		if (empty($filtros['status_any'])) {
			$where[] = 'p.status = "'.addslashes((string)($filtros['status'] ?? 'publicado')).'"';
		}
		if (!empty($filtros['id'])) {
			$where[] = 'p.id = '.(int)$filtros['id'];
		}
		if (!empty($filtros['slug'])) {
			$where[] = 'p.slug = "'.addslashes((string)$filtros['slug']).'"';
		}
		if (!empty($filtros['categoria_id'])) {
			$where[] = 'p.id_categoria = '.(int)$filtros['categoria_id'];
		}
		if (!empty($filtros['categoria_slug'])) {
			$where[] = 'cat.slug = "'.addslashes((string)$filtros['categoria_slug']).'"';
		}
		if (!empty($filtros['q'])) {
			$q = addslashes(trim((string)$filtros['q']));
			$where[] = '(p.titulo LIKE "%'.$q.'%" OR p.resumo LIKE "%'.$q.'%" OR p.corpo_html LIKE "%'.$q.'%")';
		}
		$limit = max(1, min(50, (int)($filtros['limit'] ?? 12)));
		$offset = max(0, (int)($filtros['offset'] ?? 0));
		$sql = 'SELECT p.*, cat.nome AS categoria_nome, cat.slug AS categoria_slug '
			.'FROM cj_blog_posts p '
			.'LEFT JOIN cj_blog_categorias cat ON cat.id = p.id_categoria '
			.'WHERE '.implode(' AND ', $where).' '
			.'ORDER BY COALESCE(p.publicado_em, p.created_at) DESC, p.id DESC '
			.'LIMIT '.$limit.' OFFSET '.$offset;
		$stmt = (new Database())->execute($sql);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public static function slugUnico(string $base, ?int $ignoreId = null): string {
		$slug = ConectApiMapper::slugify($base);
		$try = $slug;
		$n = 2;
		while (self::slugExiste($try, $ignoreId)) {
			$try = $slug.'-'.$n;
			$n++;
		}
		return $try;
	}

	private static function slugExiste(string $slug, ?int $ignoreId): bool {
		$sql = 'SELECT id FROM cj_blog_posts WHERE slug = "'.addslashes($slug).'"';
		if ($ignoreId) {
			$sql .= ' AND id != '.(int)$ignoreId;
		}
		$sql .= ' LIMIT 1';
		$stmt = (new Database())->execute($sql);
		return $stmt && (bool)$stmt->fetch(PDO::FETCH_ASSOC);
	}

	public static function normalizarSlugsNoBanco(): int {
		if (!self::tabelaExiste()) {
			return 0;
		}
		$stmt = (new Database())->execute('SELECT id, slug FROM cj_blog_posts');
		$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
		$atualizados = 0;
		foreach ($rows as $row) {
			$id = (int)($row['id'] ?? 0);
			$atual = (string)($row['slug'] ?? '');
			$novo = ConectApiMapper::slugify($atual);
			if ($id <= 0 || $novo === '' || $novo === $atual) {
				continue;
			}
			$novo = self::slugUnico($novo, $id);
			if ((new Database('cj_blog_posts'))->update('id = '.$id, ['slug' => $novo])) {
				$atualizados++;
			}
		}
		return $atualizados;
	}

	public static function inserir(array $dados): int {
		$db = new Database('cj_blog_posts');
		return (int)$db->insert($dados);
	}

	public static function atualizar(int $id, array $dados): bool {
		return (new Database('cj_blog_posts'))->update('id = '.(int)$id, $dados);
	}

	public static function excluir(int $id): bool {
		return (new Database('cj_blog_posts'))->delete('id = '.(int)$id);
	}
}
