<?php

namespace App\Model\Entity;

class LmsAiConversa extends LmsBase {

	public $id;
	public $id_aluno;
	public $id_admin;
	public $titulo = 'Nova conversa';
	public $messages;
	public $created_at;
	public $updated_at;

	protected static function table(): string {
		return 'lms_ai_conversas';
	}

	public static function listByAluno(int $idAluno, int $idAdmin): array {
		$stmt = self::get(
			'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin,
			'updated_at DESC, id DESC'
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
			'id_admin' => (int)$this->id_admin,
			'titulo' => $this->titulo ?: 'Nova conversa',
			'messages' => is_string($this->messages) ? $this->messages : json_encode($this->messages ?? [], JSON_UNESCAPED_UNICODE),
		];
		if (!empty($this->id)) {
			$this->updateRow((int)$this->id, (int)$this->id_admin, $dados);
			return (int)$this->id;
		}
		$this->id = $this->insertRow($dados);
		return (int)$this->id;
	}
}
