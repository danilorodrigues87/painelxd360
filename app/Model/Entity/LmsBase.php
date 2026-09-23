<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

/**
 * Base CRUD para tabelas lms_*.
 */
abstract class LmsBase {

	abstract protected static function table(): string;

	public static function get($where = null, $order = null, $limit = null, $fields = '*') {
		return (new Database(static::table()))->select($where, $order, $limit, $fields);
	}

	public static function getById(int $id) {
		return self::get('id = '.(int)$id)->fetchObject(static::class);
	}

	public static function getByIdAdmin(int $id, int $idAdmin) {
		return self::get('id = '.(int)$id.' AND id_admin = '.(int)$idAdmin)->fetchObject(static::class);
	}

	protected function insertRow(array $dados): int {
		return (int)(new Database(static::table()))->insert($dados);
	}

	protected function updateRow(int $id, int $idAdmin, array $dados): bool {
		(new Database(static::table()))->update(
			'id = '.(int)$id.' AND id_admin = '.(int)$idAdmin,
			$dados
		);
		return true;
	}

	protected function deleteRow(int $id, int $idAdmin): bool {
		(new Database(static::table()))->delete(
			'id = '.(int)$id.' AND id_admin = '.(int)$idAdmin
		);
		return true;
	}
}
