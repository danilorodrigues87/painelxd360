<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class LmsAulaComentario {

	public $id;
	public $id_admin;
	public $id_aula;
	public $id_curso;
	public $id_autor;
	public $autor_tipo = 'aluno';
	public $id_pai;
	public $texto;
	public $created_at;
	public $deleted_at;

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'lms_aula_comentarios'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getById(int $id): ?self {
		if ($id <= 0 || !self::tabelasExistem()) {
			return null;
		}
		$row = (new Database('lms_aula_comentarios'))->select('id = '.(int)$id, null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return self[] */
	public static function listByAula(int $idAula, int $idAdmin, int $limit = 100): array {
		if (!self::tabelasExistem() || $idAula <= 0) {
			return [];
		}
		$limit = max(1, min(200, $limit));
		$stmt = (new Database('lms_aula_comentarios'))->select(
			'id_aula = '.(int)$idAula.' AND id_admin = '.(int)$idAdmin.' AND deleted_at IS NULL',
			'created_at ASC',
			(string)$limit
		);
		$out = [];
		while ($row = $stmt->fetchObject(self::class)) {
			$out[] = $row;
		}
		return $out;
	}

	public function cadastrar(): int {
		$db = new Database('lms_aula_comentarios');
		$this->id = $db->insert([
			'id_admin' => (int)$this->id_admin,
			'id_aula' => (int)$this->id_aula,
			'id_curso' => $this->id_curso !== null ? (int)$this->id_curso : null,
			'id_autor' => (int)$this->id_autor,
			'autor_tipo' => (($this->autor_tipo ?? '') === 'equipe') ? 'equipe' : 'aluno',
			'id_pai' => $this->id_pai !== null && (int)$this->id_pai > 0 ? (int)$this->id_pai : null,
			'texto' => (string)$this->texto,
		]);
		return (int)$this->id;
	}

	public function softDelete(): bool {
		return (new Database('lms_aula_comentarios'))->update('id = '.(int)$this->id, [
			'deleted_at' => date('Y-m-d H:i:s'),
		]);
	}
}
