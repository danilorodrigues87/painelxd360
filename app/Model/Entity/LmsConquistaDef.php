<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class LmsConquistaDef {

	public $id;
	public $slug;
	public $titulo;
	public $subtitulo = '';
	public $descricao = '';
	public $como;
	public $icone = 'Trophy';
	public $raridade = 'bronze';
	public $badge_url;
	public $meta_tipo;
	public $meta_valor = 1;
	public $ordem = 0;
	public $ativo = 1;

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_conquistas_def'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return \PDOStatement|false */
	public static function get($where = null, $order = null, $limit = null, $fields = '*') {
		return (new Database('lms_conquistas_def'))->select($where, $order, $limit, $fields);
	}

	public static function getById(int $id): ?self {
		if ($id <= 0 || !self::tabelasExistem()) {
			return null;
		}
		$row = self::get('id = '.(int)$id, null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getBySlug(string $slug): ?self {
		$slug = trim($slug);
		if ($slug === '' || !self::tabelasExistem()) {
			return null;
		}
		$row = self::get('slug = "'.addslashes($slug).'"', null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return self[] */
	public static function listAtivas(): array {
		if (!self::tabelasExistem()) {
			return [];
		}
		$stmt = self::get('ativo = 1', 'ordem ASC, id ASC');
		$out = [];
		while ($row = $stmt->fetchObject(self::class)) {
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Defs ativas visíveis para a escola.
	 * Sem linhas em lms_escola_conquistas → todas ativas.
	 * @return self[]
	 */
	public static function listAtivasParaEscola(int $idAdmin): array {
		$todas = self::listAtivas();
		if ($idAdmin <= 0 || !$todas) {
			return $todas;
		}
		try {
			$stmt = (new Database('lms_escola_conquistas'))->select(
				'id_admin = '.(int)$idAdmin,
				null,
				null,
				'slug, ativo'
			);
			$map = [];
			$temOverride = false;
			while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$temOverride = true;
				$map[(string)$r['slug']] = (int)$r['ativo'] === 1;
			}
			if (!$temOverride) {
				return $todas;
			}
			// Slug novo sem override → ativo (não esconde medalhas adicionadas depois)
			return array_values(array_filter($todas, static function ($d) use ($map) {
				$slug = (string)$d->slug;
				return isset($map[$slug]) ? $map[$slug] : true;
			}));
		} catch (\Throwable $e) {
			return $todas;
		}
	}

	public function cadastrar(): int {
		$db = new Database('lms_conquistas_def');
		$this->id = $db->insert($this->toRow());
		return (int)$this->id;
	}

	public function atualizar(): bool {
		return (new Database('lms_conquistas_def'))->update('id = '.(int)$this->id, $this->toRow());
	}

	public function excluir(): bool {
		return (new Database('lms_conquistas_def'))->delete('id = '.(int)$this->id);
	}

	/** @return array<string,mixed> */
	private function toRow(): array {
		return [
			'slug' => (string)$this->slug,
			'titulo' => (string)$this->titulo,
			'subtitulo' => (string)($this->subtitulo ?? ''),
			'descricao' => (string)($this->descricao ?? ''),
			'como' => $this->como,
			'icone' => (string)($this->icone ?: 'Trophy'),
			'raridade' => self::normalizarRaridade($this->raridade ?? 'bronze'),
			'badge_url' => $this->badge_url ?: null,
			'meta_tipo' => (string)$this->meta_tipo,
			'meta_valor' => max(1, (int)$this->meta_valor),
			'ordem' => (int)$this->ordem,
			'ativo' => !empty($this->ativo) ? 1 : 0,
		];
	}

	public static function normalizarRaridade($r): string {
		$r = strtolower(trim((string)$r));
		$ok = ['bronze', 'prata', 'ouro', 'lendario', 'secreto'];
		return in_array($r, $ok, true) ? $r : 'bronze';
	}
}
