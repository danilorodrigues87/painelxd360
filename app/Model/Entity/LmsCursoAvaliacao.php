<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class LmsCursoAvaliacao {

	public $id, $id_admin, $id_aluno, $id_curso, $nota, $comentario, $criado_em, $atualizado_em;

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_curso_avaliacoes'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getByAlunoCurso(int $idAluno, int $idCurso): ?self {
		if (!self::tabelasExistem()) {
			return null;
		}
		$row = (new Database('lms_curso_avaliacoes'))->select(
			'id_aluno = '.(int)$idAluno.' AND id_curso = '.(int)$idCurso,
			null, '1'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return array{avg:float,count:int} */
	public static function mediaCurso(int $idCurso, int $idAdmin): array {
		if (!self::tabelasExistem()) {
			return ['avg' => 0.0, 'count' => 0];
		}
		$row = (new Database('lms_curso_avaliacoes'))->select(
			'id_curso = '.(int)$idCurso.' AND id_admin = '.(int)$idAdmin,
			null, null,
			'AVG(nota) AS media, COUNT(*) AS total'
		)->fetch(PDO::FETCH_ASSOC);
		$count = (int)($row['total'] ?? 0);
		$avg = $count > 0 ? round((float)$row['media'], 1) : 0.0;
		return ['avg' => $avg, 'count' => $count];
	}

	public function salvar(): bool {
		$nota = max(1, min(5, (int)$this->nota));
		$comentario = $this->comentario !== null && $this->comentario !== ''
			? mb_substr(trim((string)$this->comentario), 0, 500)
			: null;
		$existente = self::getByAlunoCurso((int)$this->id_aluno, (int)$this->id_curso);
		if ($existente) {
			$this->id = $existente->id;
			return (new Database('lms_curso_avaliacoes'))->update('id = '.(int)$this->id, [
				'nota' => $nota,
				'comentario' => $comentario,
			]);
		}
		$this->id = (new Database('lms_curso_avaliacoes'))->insert([
			'id_admin' => (int)$this->id_admin,
			'id_aluno' => (int)$this->id_aluno,
			'id_curso' => (int)$this->id_curso,
			'nota' => $nota,
			'comentario' => $comentario,
		]);
		return true;
	}
}
