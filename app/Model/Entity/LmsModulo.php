<?php

namespace App\Model\Entity;

class LmsModulo extends LmsBase {

	public $id;
	public $id_curso;
	public $id_admin;
	public $titulo;
	public $ordem = 0;
	public $bloqueado = 0;

	protected static function table(): string {
		return 'lms_modulos';
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

	public function salvar(): int {
		$dados = [
			'id_curso' => (int)$this->id_curso,
			'id_admin' => (int)$this->id_admin,
			'titulo' => $this->titulo,
			'ordem' => (int)$this->ordem,
			'bloqueado' => (int)$this->bloqueado,
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
