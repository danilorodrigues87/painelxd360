<?php

namespace App\Model\Entity;

class LmsQuestao extends LmsBase {

	public $id;
	public $id_atividade;
	public $id_admin;
	public $tipo = 'multiple';
	public $enunciado;
	public $opcoes;
	public $resposta_correta;
	public $ordem = 0;

	protected static function table(): string {
		return 'lms_questoes';
	}

	public static function listByAtividade(int $idAtividade, int $idAdmin): array {
		$stmt = self::get(
			'id_atividade = '.(int)$idAtividade.' AND id_admin = '.(int)$idAdmin,
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
			'id_atividade' => (int)$this->id_atividade,
			'id_admin' => (int)$this->id_admin,
			'tipo' => in_array($this->tipo, ['multiple', 'boolean', 'essay'], true) ? $this->tipo : 'multiple',
			'enunciado' => $this->enunciado,
			'opcoes' => is_string($this->opcoes) ? $this->opcoes : json_encode($this->opcoes ?? [], JSON_UNESCAPED_UNICODE),
			'resposta_correta' => $this->resposta_correta,
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
