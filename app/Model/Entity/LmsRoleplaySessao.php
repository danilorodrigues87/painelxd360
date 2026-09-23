<?php

namespace App\Model\Entity;

class LmsRoleplaySessao extends LmsBase {

	public $id;
	public $id_cenario;
	public $id_aluno;
	public $id_admin;
	public $difficulty = 'medium';
	public $status = 'in_progress';
	public $messages;
	public $score;
	public $evaluation;
	public $started_at;
	public $ended_at;
	public $duration_seconds = 0;
	public $ciclo = 1;

	protected static function table(): string {
		return 'lms_roleplay_sessoes';
	}

	public static function listByAluno(int $idAluno, int $idAdmin): array {
		$stmt = self::get(
			'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin,
			'id DESC'
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	public static function listByAlunoCenarioCiclo(int $idAluno, int $idCenario, int $idAdmin, int $ciclo): array {
		$stmt = self::get(
			'id_aluno = '.(int)$idAluno.' AND id_cenario = '.(int)$idCenario
			.' AND id_admin = '.(int)$idAdmin.' AND ciclo = '.(int)$ciclo,
			'id DESC'
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	public function salvar(): int {
		$dados = [
			'id_cenario' => (int)$this->id_cenario,
			'id_aluno' => (int)$this->id_aluno,
			'id_admin' => (int)$this->id_admin,
			'difficulty' => $this->difficulty ?: 'medium',
			'status' => $this->status ?: 'in_progress',
			'messages' => is_string($this->messages) ? $this->messages : json_encode($this->messages ?? [], JSON_UNESCAPED_UNICODE),
			'score' => $this->score,
			'evaluation' => is_string($this->evaluation) ? $this->evaluation : json_encode($this->evaluation ?? null, JSON_UNESCAPED_UNICODE),
			'ended_at' => $this->ended_at,
			'duration_seconds' => (int)$this->duration_seconds,
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
