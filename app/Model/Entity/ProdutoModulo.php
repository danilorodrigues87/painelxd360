<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class ProdutoModulo {

	public static function tabelaExiste(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('produto_modulos'))->execute("SHOW TABLES LIKE 'produto_modulos'")->fetch(\PDO::FETCH_NUM);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	/** @return array<int,array{slug:string,rotulo:string}> */
	public static function listar(string $produtoSlug): array {
		if (!self::tabelaExiste() || $produtoSlug === '') {
			return [];
		}
		$slug = addslashes($produtoSlug);
		$rows = (new Database('produto_modulos'))->select(
			"produto_slug = '{$slug}' AND ativo = 1",
			'ordem ASC, rotulo ASC'
		);
		$out = [];
		while ($r = $rows->fetch(\PDO::FETCH_ASSOC)) {
			$out[] = [
				'slug'   => (string)$r['slug'],
				'rotulo' => (string)$r['rotulo'],
			];
		}
		return $out;
	}

	/** @return string[] */
	public static function slugs(string $produtoSlug): array {
		return array_column(self::listar($produtoSlug), 'slug');
	}
}
