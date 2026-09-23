<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class LmsCertificado {

	public $id;
	public $id_admin;
	public $id_aluno;
	public $id_curso;
	public $id_trilha = 0;
	public $titulo_curso = '';
	public $nome_escola = '';
	public $carga_h = 0;
	public $modulos;
	public $codigo;
	public $conclusao;
	public $created_at;

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_certificados'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getById(int $id): ?self {
		if (!self::tabelasExistem() || $id <= 0) {
			return null;
		}
		$row = (new Database('lms_certificados'))->select('id = '.(int)$id)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getByAlunoCurso(int $idAluno, int $idCurso): ?self {
		if (!self::tabelasExistem()) {
			return null;
		}
		$row = (new Database('lms_certificados'))->select(
			'id_aluno = '.(int)$idAluno.' AND id_curso = '.(int)$idCurso,
			null,
			'1'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return \PDOStatement|false */
	public static function listByAluno(int $idAluno, int $idAdmin) {
		return (new Database('lms_certificados'))->select(
			'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin,
			'id DESC'
		);
	}

	public static function countByAluno(int $idAluno, int $idAdmin): int {
		if (!self::tabelasExistem()) {
			return 0;
		}
		$row = (new Database('lms_certificados'))->select(
			'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin,
			null,
			null,
			'COUNT(*) AS total'
		)->fetch(PDO::FETCH_ASSOC);
		return (int)($row['total'] ?? 0);
	}

	public function cadastrar(): int {
		$db = new Database('lms_certificados');
		if (!$this->codigo) {
			$this->codigo = bin2hex(random_bytes(8));
		}
		$this->id = $db->insert([
			'id_admin' => (int)$this->id_admin,
			'id_aluno' => (int)$this->id_aluno,
			'id_curso' => (int)$this->id_curso,
			'id_trilha' => (int)$this->id_trilha,
			'titulo_curso' => (string)$this->titulo_curso,
			'nome_escola' => (string)$this->nome_escola,
			'carga_h' => (int)$this->carga_h,
			'modulos' => $this->modulos,
			'codigo' => (string)$this->codigo,
			'conclusao' => (string)$this->conclusao,
		]);
		return (int)$this->id;
	}

	/** Atualiza snapshot (mantém codigo). */
	public function atualizar(): bool {
		if ((int)$this->id <= 0) {
			return false;
		}
		return (new Database('lms_certificados'))->update('id = '.(int)$this->id, [
			'id_trilha' => (int)$this->id_trilha,
			'titulo_curso' => (string)$this->titulo_curso,
			'nome_escola' => (string)$this->nome_escola,
			'carga_h' => (int)$this->carga_h,
			'modulos' => $this->modulos,
			'conclusao' => (string)$this->conclusao,
		]);
	}
}
