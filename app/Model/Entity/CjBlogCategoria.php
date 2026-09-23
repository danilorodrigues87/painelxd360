<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjBlogCategoria {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_blog_categorias'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarAtivas(): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$stmt = (new Database())->execute(
			'SELECT * FROM cj_blog_categorias WHERE ativo = 1 ORDER BY ordem ASC, nome ASC'
		);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarTodas(): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$stmt = (new Database())->execute(
			'SELECT * FROM cj_blog_categorias ORDER BY ordem ASC, nome ASC'
		);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public static function getById(int $id): ?array {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$stmt = (new Database())->execute('SELECT * FROM cj_blog_categorias WHERE id = '.(int)$id.' LIMIT 1');
		$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
		return is_array($row) ? $row : null;
	}

	public static function getBySlug(string $slug): ?array {
		if (!self::tabelaExiste() || $slug === '') {
			return null;
		}
		$stmt = (new Database())->execute(
			'SELECT * FROM cj_blog_categorias WHERE slug = "'.addslashes($slug).'" LIMIT 1'
		);
		$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
		return is_array($row) ? $row : null;
	}
}
