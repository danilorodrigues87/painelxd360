<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class LmsConquistaAluno {

	public $id;
	public $id_admin;
	public $id_aluno;
	public $slug;
	public $progresso = 0;
	public $meta = 1;
	public $unlocked_at;
	public $origem = 'auto';
	public $updated_at;

	public static function getByAlunoSlug(int $idAluno, string $slug): ?self {
		$row = (new Database('lms_conquistas_aluno'))->select(
			'id_aluno = '.(int)$idAluno.' AND slug = "'.addslashes($slug).'"',
			null,
			'1'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return array<string,self> keyed by slug */
	public static function mapByAluno(int $idAluno): array {
		$stmt = (new Database('lms_conquistas_aluno'))->select('id_aluno = '.(int)$idAluno);
		$out = [];
		while ($row = $stmt->fetchObject(self::class)) {
			$out[(string)$row->slug] = $row;
		}
		return $out;
	}

	public function salvar(): void {
		$db = new Database('lms_conquistas_aluno');
		$existing = self::getByAlunoSlug((int)$this->id_aluno, (string)$this->slug);
		$origem = (($this->origem ?? 'auto') === 'manual') ? 'manual' : 'auto';
		$data = [
			'id_admin' => (int)$this->id_admin,
			'id_aluno' => (int)$this->id_aluno,
			'slug' => (string)$this->slug,
			'progresso' => (int)$this->progresso,
			'meta' => (int)$this->meta,
			'unlocked_at' => $this->unlocked_at,
			'origem' => $origem,
		];
		if ($existing instanceof self) {
			$db->update('id = '.(int)$existing->id, $data);
			$this->id = (int)$existing->id;
			return;
		}
		$this->id = $db->insert($data);
	}
}
