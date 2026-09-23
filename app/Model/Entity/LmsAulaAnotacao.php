<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class LmsAulaAnotacao {

	public $id;
	public $id_admin;
	public $id_aluno;
	public $id_aula;
	public $id_curso;
	public $texto;
	public $created_at;
	public $updated_at;

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'lms_aula_anotacoes'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getByAlunoAula(int $idAluno, int $idAula, int $idAdmin): ?self {
		if (!self::tabelasExistem() || $idAluno <= 0 || $idAula <= 0) {
			return null;
		}
		$row = (new Database('lms_aula_anotacoes'))->select(
			'id_aluno = '.(int)$idAluno.' AND id_aula = '.(int)$idAula.' AND id_admin = '.(int)$idAdmin,
			null,
			'1'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public function cadastrar(): int {
		$db = new Database('lms_aula_anotacoes');
		$this->id = $db->insert([
			'id_admin' => (int)$this->id_admin,
			'id_aluno' => (int)$this->id_aluno,
			'id_aula' => (int)$this->id_aula,
			'id_curso' => $this->id_curso !== null ? (int)$this->id_curso : null,
			'texto' => (string)$this->texto,
		]);
		return (int)$this->id;
	}

	public function atualizarTexto(string $texto): bool {
		return (new Database('lms_aula_anotacoes'))->update('id = '.(int)$this->id, [
			'texto' => $texto,
		]);
	}
}
