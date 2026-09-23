<?php

namespace App\Common\Helpers;

use App\Model\Db\Database;
use PDO;

class ConectSchemaHelper {

	/** @var array<string,list<string>> */
	private static array $colunas = [];

	/** @return list<string> */
	public static function colunas(string $tabela): array {
		if (isset(self::$colunas[$tabela])) {
			return self::$colunas[$tabela];
		}
		try {
			$stmt = (new Database())->execute('SHOW COLUMNS FROM `'.str_replace('`', '', $tabela).'`');
			self::$colunas[$tabela] = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
		} catch (\Throwable $e) {
			self::$colunas[$tabela] = [];
		}
		return self::$colunas[$tabela];
	}

	public static function temColuna(string $tabela, string $coluna): bool {
		return in_array($coluna, self::colunas($tabela), true);
	}

	/**
	 * @param array<string,mixed> $dados
	 * @return array<string,mixed>
	 */
	public static function filtrar(string $tabela, array $dados): array {
		$cols = self::colunas($tabela);
		if ($cols === []) {
			return $dados;
		}
		$out = [];
		foreach ($dados as $k => $v) {
			if (in_array($k, $cols, true)) {
				$out[$k] = $v;
			}
		}
		return $out;
	}

	/** @param list<string> $requeridas */
	public static function faltando(string $tabela, array $requeridas): array {
		$cols = self::colunas($tabela);
		return array_values(array_filter($requeridas, static fn ($c) => !in_array($c, $cols, true)));
	}
}
