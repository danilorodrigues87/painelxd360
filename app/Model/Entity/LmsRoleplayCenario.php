<?php

namespace App\Model\Entity;

class LmsRoleplayCenario extends LmsBase {

	public $id;
	public $id_curso;
	public $id_modulo;
	public $id_aula;
	public $id_admin;
	public $titulo;
	public $tema;
	public $cenario;
	public $user_role;
	public $ai_role;
	public $ai_character_name;
	public $ai_character_avatar_url;
	public $objectives;
	public $criteria;
	public $difficulty = 'medium';
	public $min_score = 70;
	public $base_prompt;
	public $initial_personality;
	public $initial_message;
	public $estimated_minutes = 15;

	protected static function table(): string {
		return 'lms_roleplay_cenarios';
	}

	public static function listByCurso(int $idCurso, int $idAdmin): array {
		$stmt = self::get(
			'id_curso = '.(int)$idCurso.' AND id_admin = '.(int)$idAdmin,
			'id DESC'
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
			'id ASC'
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	/** Roleplays do módulo sem aula específica. */
	public static function listByModuloSemAula(int $idModulo, int $idAdmin): array {
		$stmt = self::get(
			'id_modulo = '.(int)$idModulo.' AND id_admin = '.(int)$idAdmin
			.' AND (id_aula IS NULL OR id_aula = 0)',
			'id ASC'
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
			'id_modulo' => $this->id_modulo ? (int)$this->id_modulo : null,
			'id_aula' => $this->id_aula ? (int)$this->id_aula : null,
			'id_admin' => (int)$this->id_admin,
			'titulo' => $this->titulo,
			'tema' => $this->tema,
			'cenario' => $this->cenario,
			'user_role' => $this->user_role,
			'ai_role' => $this->ai_role,
			'ai_character_name' => $this->ai_character_name,
			'ai_character_avatar_url' => $this->ai_character_avatar_url,
			'objectives' => is_string($this->objectives) ? $this->objectives : json_encode($this->objectives ?? [], JSON_UNESCAPED_UNICODE),
			'criteria' => is_string($this->criteria) ? $this->criteria : json_encode($this->criteria ?? [], JSON_UNESCAPED_UNICODE),
			'difficulty' => $this->difficulty ?: 'medium',
			'min_score' => (int)$this->min_score,
			'base_prompt' => $this->base_prompt,
			'initial_personality' => $this->initial_personality,
			'initial_message' => $this->initial_message,
			'estimated_minutes' => (int)$this->estimated_minutes,
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
