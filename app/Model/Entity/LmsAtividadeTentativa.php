<?php

namespace App\Model\Entity;

class LmsAtividadeTentativa extends LmsBase {

	public $id;
	public $id_aluno;
	public $id_atividade;
	public $id_admin;
	public $respostas;
	public $nota;
	public $feedback;
	public $strengths;
	public $improvements;
	public $competencies;
	public $status = 'completed';
	public $ciclo = 1;
	public $created_at;

	protected static function table(): string {
		return 'lms_atividade_tentativas';
	}

	public static function listByAlunoAtividade(int $idAluno, int $idAtividade): array {
		$stmt = self::get(
			'id_aluno = '.(int)$idAluno.' AND id_atividade = '.(int)$idAtividade,
			'id DESC'
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	public static function listByAlunoAtividadeCiclo(int $idAluno, int $idAtividade, int $ciclo): array {
		$stmt = self::get(
			'id_aluno = '.(int)$idAluno.' AND id_atividade = '.(int)$idAtividade
			.' AND ciclo = '.(int)$ciclo,
			'id DESC'
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	public static function getInProgress(int $idAluno, int $idAtividade, ?int $ciclo = null): ?self {
		$where = 'id_aluno = '.(int)$idAluno.' AND id_atividade = '.(int)$idAtividade
			." AND status = 'in_progress'";
		if ($ciclo !== null) {
			$where .= ' AND ciclo = '.(int)$ciclo;
		}
		$row = self::get($where, 'id DESC', '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function countCompleted(int $idAluno, int $idAtividade): int {
		$stmt = self::get(
			'id_aluno = '.(int)$idAluno.' AND id_atividade = '.(int)$idAtividade
			." AND (status = 'completed' OR (status IS NULL AND nota IS NOT NULL))",
			null,
			null,
			'COUNT(*) AS c'
		);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return (int)($row['c'] ?? 0);
	}

	public static function countCompletedCiclo(int $idAluno, int $idAtividade, int $ciclo): int {
		$stmt = self::get(
			'id_aluno = '.(int)$idAluno.' AND id_atividade = '.(int)$idAtividade
			.' AND ciclo = '.(int)$ciclo
			." AND (status = 'completed' OR (status IS NULL AND nota IS NOT NULL))",
			null,
			null,
			'COUNT(*) AS c'
		);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return (int)($row['c'] ?? 0);
	}

	/** Tentativas com nota >= 70 (aprovado). */
	public static function listPassedByAluno(int $idAluno): array {
		$stmt = self::get(
			'id_aluno = '.(int)$idAluno.' AND nota IS NOT NULL AND nota >= 70',
			'id DESC'
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	public function decodeRespostas(): array {
		$raw = $this->respostas;
		if (is_array($raw)) {
			return $raw;
		}
		$decoded = json_decode((string)($raw ?? '{}'), true);
		return is_array($decoded) ? $decoded : [];
	}

	public function salvar(): int {
		$dados = [
			'id_aluno' => (int)$this->id_aluno,
			'id_atividade' => (int)$this->id_atividade,
			'id_admin' => (int)$this->id_admin,
			'respostas' => is_string($this->respostas)
				? $this->respostas
				: json_encode($this->respostas ?? [], JSON_UNESCAPED_UNICODE),
			'nota' => $this->nota,
			'feedback' => $this->feedback,
			'strengths' => is_string($this->strengths)
				? $this->strengths
				: json_encode($this->strengths ?? [], JSON_UNESCAPED_UNICODE),
			'improvements' => is_string($this->improvements)
				? $this->improvements
				: json_encode($this->improvements ?? [], JSON_UNESCAPED_UNICODE),
			'competencies' => is_string($this->competencies)
				? $this->competencies
				: json_encode($this->competencies ?? [], JSON_UNESCAPED_UNICODE),
			'status' => in_array((string)$this->status, ['in_progress', 'completed'], true)
				? $this->status
				: 'completed',
			'ciclo' => max(1, (int)($this->ciclo ?? 1)),
		];
		if (!empty($this->id)) {
			$this->updateRow((int)$this->id, (int)$this->id_admin, $dados);
			return (int)$this->id;
		}
		$this->id = $this->insertRow($dados);
		return (int)$this->id;
	}
}
