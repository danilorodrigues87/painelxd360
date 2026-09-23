<?php

namespace App\Model\Entity;

class LmsProgressoAula extends LmsBase {

	public $id;
	public $id_aluno;
	public $id_aula;
	public $id_admin;
	public $concluida_em;
	public $ultimo_acesso;
	public $ciclo = 1;
	public $precisa_revisar = 0;
	public $nota_unidade;
	public $unidade_aprovada = 0;

	protected static function table(): string {
		return 'lms_progresso_aula';
	}

	public static function getAlunoAula(int $idAluno, int $idAula) {
		return self::get(
			'id_aluno = '.(int)$idAluno.' AND id_aula = '.(int)$idAula
		)->fetchObject(self::class);
	}

	public static function listByAluno(int $idAluno, int $idAdmin): array {
		$stmt = self::get(
			'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	public function salvar(): int {
		$dados = [
			'id_aluno' => (int)$this->id_aluno,
			'id_aula' => (int)$this->id_aula,
			'id_admin' => (int)$this->id_admin,
			'concluida_em' => $this->concluida_em,
			'ultimo_acesso' => $this->ultimo_acesso,
			'ciclo' => max(1, (int)($this->ciclo ?? 1)),
			'precisa_revisar' => (int)($this->precisa_revisar ?? 0) ? 1 : 0,
			'nota_unidade' => $this->nota_unidade,
			'unidade_aprovada' => (int)($this->unidade_aprovada ?? 0) ? 1 : 0,
		];
		$exist = self::getAlunoAula((int)$this->id_aluno, (int)$this->id_aula);
		if ($exist instanceof self) {
			$this->id = $exist->id;
			$this->updateRow((int)$this->id, (int)$this->id_admin, $dados);
			return (int)$this->id;
		}
		$this->id = $this->insertRow($dados);
		return (int)$this->id;
	}
}
