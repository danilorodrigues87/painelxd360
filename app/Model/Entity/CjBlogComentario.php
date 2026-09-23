<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjBlogComentario {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_blog_comentarios'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarPorPost(int $postId, int $limit = 100, int $offset = 0): array {
		if (!self::tabelaExiste() || $postId <= 0) {
			return [];
		}
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);
		$stmt = (new Database())->execute(
			'SELECT c.*, '
			.'cand.foto AS candidato_foto, '
			.'emp.logo AS empresa_logo '
			.'FROM cj_blog_comentarios c '
			.'LEFT JOIN cj_candidatos cand ON cand.id_usuario = c.id_usuario AND c.tipo_autor = "candidato" '
			.'LEFT JOIN cj_empresas emp ON emp.id_usuario = c.id_usuario AND c.tipo_autor = "empresa" '
			.'WHERE c.id_post = '.(int)$postId
			.' AND c.status = "publicado" ORDER BY c.created_at ASC LIMIT '.$limit.' OFFSET '.$offset
		);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public static function contarPorPost(int $postId): int {
		if (!self::tabelaExiste() || $postId <= 0) {
			return 0;
		}
		$stmt = (new Database())->execute(
			'SELECT COUNT(*) AS c FROM cj_blog_comentarios WHERE id_post = '.(int)$postId.' AND status = "publicado"'
		);
		$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
		return (int)($row['c'] ?? 0);
	}

	public static function getById(int $id): ?array {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$stmt = (new Database())->execute('SELECT * FROM cj_blog_comentarios WHERE id = '.(int)$id.' LIMIT 1');
		$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
		return is_array($row) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarRecentes(int $limit = 50): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$limit = max(1, min(100, $limit));
		$stmt = (new Database())->execute(
			'SELECT c.*, p.titulo AS post_titulo, p.slug AS post_slug FROM cj_blog_comentarios c '
			.'INNER JOIN cj_blog_posts p ON p.id = c.id_post '
			.'WHERE c.status = "publicado" ORDER BY c.created_at DESC LIMIT '.$limit
		);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public static function inserir(array $dados): int {
		return (int)(new Database('cj_blog_comentarios'))->insert($dados);
	}

	public static function atualizar(int $id, array $dados): bool {
		return (new Database('cj_blog_comentarios'))->update('id = '.(int)$id, $dados);
	}
}
