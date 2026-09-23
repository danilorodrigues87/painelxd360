<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class PlatformHelpCategoria {

	public $id;
	public $titulo;
	public $slug;
	public $ordem = 0;
	public $ativo = 1;
	public $created_at;

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'platform_help_categorias'");
			$ok = $st && $st->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return self[] */
	public static function listAll(bool $somenteAtivas = false): array {
		$where = $somenteAtivas ? 'ativo = 1' : '1=1';
		$stmt = (new Database('platform_help_categorias'))->select($where, 'ordem ASC, id ASC');
		$out = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$out[] = $r;
		}
		return $out;
	}

	public static function getById(int $id): ?self {
		$row = (new Database('platform_help_categorias'))->select('id = '.(int)$id)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getBySlug(string $slug): ?self {
		$slug = addslashes($slug);
		$row = (new Database('platform_help_categorias'))->select('slug = "'.$slug.'"')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public function salvar(): int {
		$dados = [
			'titulo' => $this->titulo,
			'slug' => $this->slug,
			'ordem' => (int)$this->ordem,
			'ativo' => (int)$this->ativo ? 1 : 0,
		];
		$db = new Database('platform_help_categorias');
		if (!empty($this->id)) {
			$db->update('id = '.(int)$this->id, $dados);
			return (int)$this->id;
		}
		$this->id = (int)$db->insert($dados);
		return (int)$this->id;
	}

	public function excluir(): bool {
		(new Database('platform_help_artigos'))->delete('id_categoria = '.(int)$this->id);
		return (bool)(new Database('platform_help_categorias'))->delete('id = '.(int)$this->id);
	}
}
