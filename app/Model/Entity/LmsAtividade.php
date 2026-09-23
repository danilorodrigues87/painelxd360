<?php

namespace App\Model\Entity;

class LmsAtividade extends LmsBase {

	public $id;
	public $id_curso;
	public $id_aula;
	public $id_admin;
	public $titulo;
	public $descricao;
	public $duracao_min = 30;
	public $tentativas_max = 3;
	public $ordem = 0;

	protected static function table(): string {
		return 'lms_atividades';
	}

	public static function listByCurso(int $idCurso, int $idAdmin): array {
		$stmt = self::get(
			'id_curso = '.(int)$idCurso.' AND id_admin = '.(int)$idAdmin,
			'ordem ASC, id ASC'
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	public static function listByAula(int $idAula, int $idAdmin): array {
		$stmt = self::get(
			'id_aula = '.(int)$idAula.' AND id_admin = '.(int)$idAdmin,
			'ordem ASC, id ASC'
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	public function salvar(): int {
		$dados = [
			'id_curso' => (int)$this->id_curso,
			'id_aula' => $this->id_aula !== null && $this->id_aula !== '' ? (int)$this->id_aula : null,
			'id_admin' => (int)$this->id_admin,
			'titulo' => $this->titulo,
			'descricao' => $this->descricao,
			'duracao_min' => (int)$this->duracao_min,
			'tentativas_max' => (int)$this->tentativas_max,
			'ordem' => (int)$this->ordem,
		];
		if (!empty($this->id)) {
			$this->updateRow((int)$this->id, (int)$this->id_admin, $dados);
			return (int)$this->id;
		}
		$this->id = $this->insertRow($dados);
		return (int)$this->id;
	}

	public function excluir(): bool {
		return $this->deleteRow((int)$this->id, (int)$this->id_admin);
	}
}
