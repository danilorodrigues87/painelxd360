<?php

namespace App\Model\Entity;

class LmsMaterial extends LmsBase {

	public $id;
	public $id_aula;
	public $id_admin;
	public $label;
	public $url;
	public $tipo = 'link';
	public $ordem = 0;

	protected static function table(): string {
		return 'lms_materiais';
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
			'id_aula' => (int)$this->id_aula,
			'id_admin' => (int)$this->id_admin,
			'label' => $this->label,
			'url' => $this->url,
			'tipo' => in_array($this->tipo, ['pdf', 'link', 'file'], true) ? $this->tipo : 'link',
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
