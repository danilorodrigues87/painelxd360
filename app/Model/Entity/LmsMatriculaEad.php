<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class LmsMatriculaEad extends LmsBase {

	public $id;
	public $id_admin;
	public $id_aluno;
	public $id_curso;
	public $ativo = 1;
	public $inicio;
	public $fim;
	public $created_at;
	public $created_by;
	public $updated_at;

	protected static function table(): string {
		return 'lms_matricula_ead';
	}

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_matricula_ead'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getAtiva(int $idAluno, int $idCurso): ?self {
		if (!self::tabelaExiste()) {
			return null;
		}
		$row = self::get(
			'id_aluno = '.(int)$idAluno
			.' AND id_curso = '.(int)$idCurso
			.' AND ativo = 1'
			.' AND (inicio IS NULL OR inicio <= CURDATE())'
			.' AND (fim IS NULL OR fim >= CURDATE())'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return self[] */
	public static function listAtivasAluno(int $idAluno, int $idAdmin): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$stmt = self::get(
			'id_aluno = '.(int)$idAluno
			.' AND id_admin = '.(int)$idAdmin
			.' AND ativo = 1'
			.' AND (inicio IS NULL OR inicio <= CURDATE())'
			.' AND (fim IS NULL OR fim >= CURDATE())',
			'id DESC'
		);
		$out = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$out[] = $r;
		}
		return $out;
	}

	/** @return self[] */
	public static function listByCurso(int $idCurso, int $idAdminEscola): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$stmt = self::get(
			'id_curso = '.(int)$idCurso
			.' AND id_admin = '.(int)$idAdminEscola
			.' AND ativo = 1',
			'id DESC'
		);
		$out = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$out[] = $r;
		}
		return $out;
	}

	public function salvar(): int {
		$dados = [
			'id_admin' => (int)$this->id_admin,
			'id_aluno' => (int)$this->id_aluno,
			'id_curso' => (int)$this->id_curso,
			'ativo' => (int)$this->ativo,
			'inicio' => $this->inicio ?: null,
			'fim' => $this->fim ?: null,
			'created_by' => $this->created_by !== null && $this->created_by !== '' ? (int)$this->created_by : null,
		];
		if (!empty($this->id)) {
			unset($dados['created_by']);
			$this->updateRow((int)$this->id, (int)$this->id_admin, $dados);
			return (int)$this->id;
		}
		$this->id = $this->insertRow($dados);
		return (int)$this->id;
	}
}
