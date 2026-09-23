<?php

namespace App\Model\Entity;

class LmsCurso extends LmsBase {

	public $id;
	public $id_admin;
	public $id_trilha;
	public $titulo;
	public $carga_h;
	public $slug;
	public $short_description;
	public $cover_url;
	public $banner_url;
	public $level = 'Iniciante';
	public $objectives;
	public $instructor_name;
	public $instructor_title;
	public $instructor_bio;
	public $instructor_avatar_url;
	public $publicado = 0;
	public $vitrine_ativo = 0;
	public $vitrine_preco_mensal = 0;
	public $vitrine_descricao;
	public $origem = 'escola';
	public $created_at;
	public $updated_at;

	protected static function table(): string {
		return 'lms_cursos';
	}

	public static function getByTrilha(int $idTrilha, int $idAdmin) {
		return self::get(
			'id_trilha = '.(int)$idTrilha.' AND id_admin = '.(int)$idAdmin
		)->fetchObject(self::class);
	}

	public static function getBySlug(string $slug, int $idAdmin) {
		$slug = addslashes($slug);
		return self::get(
			"slug = '{$slug}' AND id_admin = ".(int)$idAdmin
		)->fetchObject(self::class);
	}

	/** Nome exibido no portal/admin. */
	public function nomeExibicao(): string {
		$t = trim((string)($this->titulo ?? ''));
		if ($t !== '') {
			return $t;
		}
		if (!empty($this->id_trilha)) {
			$trilha = Trilhas::getTrilhaById((int)$this->id_trilha);
			if ($trilha) {
				return (string)$trilha->nome;
			}
		}
		return (string)($this->slug ?: 'Curso');
	}

	public function salvar(): int {
		$idTrilha = $this->id_trilha !== null && $this->id_trilha !== '' && (int)$this->id_trilha > 0
			? (int)$this->id_trilha
			: null;
		$dados = [
			'id_admin' => (int)$this->id_admin,
			'id_trilha' => $idTrilha,
			'slug' => $this->slug,
			'short_description' => $this->short_description,
			'cover_url' => $this->cover_url,
			'banner_url' => $this->banner_url,
			'level' => $this->level ?: 'Iniciante',
			'objectives' => is_string($this->objectives)
				? $this->objectives
				: json_encode($this->objectives ?? [], JSON_UNESCAPED_UNICODE),
			'instructor_name' => $this->instructor_name,
			'instructor_title' => $this->instructor_title,
			'instructor_bio' => $this->instructor_bio,
			'instructor_avatar_url' => $this->instructor_avatar_url,
			'publicado' => (int)$this->publicado,
		];

		if (self::temColunaTitulo()) {
			$dados['titulo'] = $this->titulo !== null && $this->titulo !== '' ? (string)$this->titulo : null;
			$dados['carga_h'] = $this->carga_h !== null && $this->carga_h !== '' ? (int)$this->carga_h : null;
		}

		if (self::temColunaVitrine()) {
			$dados['vitrine_ativo'] = (int)$this->vitrine_ativo;
			$dados['vitrine_preco_mensal'] = round((float)$this->vitrine_preco_mensal, 2);
			$dados['vitrine_descricao'] = $this->vitrine_descricao;
		}

		if (self::temColunaOrigem()) {
			$dados['origem'] = in_array((string)$this->origem, ['escola', 'cti'], true)
				? (string)$this->origem
				: 'escola';
		}

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

	public static function temColunaTitulo(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new \App\Model\Db\Database();
			$stmt = $db->execute("SHOW COLUMNS FROM `lms_cursos` LIKE 'titulo'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function temColunaVitrine(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new \App\Model\Db\Database();
			$stmt = $db->execute("SHOW COLUMNS FROM `lms_cursos` LIKE 'vitrine_ativo'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function temColunaOrigem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new \App\Model\Db\Database();
			$stmt = $db->execute("SHOW COLUMNS FROM `lms_cursos` LIKE 'origem'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return LmsCurso[] */
	public static function listarPorOrigem(string $origem, int $idAdmin): array {
		if (!self::temColunaOrigem()) {
			return [];
		}
		$origem = addslashes($origem);
		$order = self::temColunaTitulo() ? 'titulo ASC, id DESC' : 'id DESC';
		$results = self::get(
			"origem = '{$origem}' AND id_admin = ".(int)$idAdmin,
			$order
		);
		$out = [];
		while ($c = $results->fetchObject(self::class)) {
			$out[] = $c;
		}
		return $out;
	}
}
