<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjAnuncioPlano {

	public $id;
	public $slug;
	public $nome;
	public $descricao;
	public $max_anuncios = 1;
	public $valor_mensal = 99;
	public $ativo = 1;
	public $ordem = 0;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_anuncio_planos'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getById(int $id): ?self {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$row = (new Database('cj_anuncio_planos'))->select('id = '.(int)$id, null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getBySlug(string $slug): ?self {
		if (!self::tabelaExiste() || trim($slug) === '') {
			return null;
		}
		$row = (new Database('cj_anuncio_planos'))->select(
			'slug = "'.addslashes(trim($slug)).'"',
			null,
			'1'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return array<int,self> */
	public static function listarAtivos(): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$stmt = (new Database('cj_anuncio_planos'))->select('ativo = 1', 'ordem ASC, id ASC');
		$out = [];
		while ($row = $stmt->fetchObject(self::class)) {
			if ($row instanceof self) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarTodos(): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$stmt = (new Database('cj_anuncio_planos'))->select(null, 'ordem ASC, id ASC');
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public function cadastrar(): bool {
		$this->id = (int)(new Database('cj_anuncio_planos'))->insert([
			'slug'         => mb_substr(trim((string)$this->slug), 0, 40),
			'nome'         => mb_substr(trim((string)$this->nome), 0, 120),
			'descricao'    => $this->descricao !== null ? mb_substr((string)$this->descricao, 0, 500) : null,
			'max_anuncios' => max(1, (int)$this->max_anuncios),
			'valor_mensal' => round((float)$this->valor_mensal, 2),
			'ativo'        => (int)$this->ativo ? 1 : 0,
			'ordem'        => (int)$this->ordem,
		]);
		return $this->id > 0;
	}

	public function atualizar(): bool {
		return (bool)(new Database('cj_anuncio_planos'))->update('id = '.(int)$this->id, [
			'slug'         => mb_substr(trim((string)$this->slug), 0, 40),
			'nome'         => mb_substr(trim((string)$this->nome), 0, 120),
			'descricao'    => $this->descricao !== null ? mb_substr((string)$this->descricao, 0, 500) : null,
			'max_anuncios' => max(1, (int)$this->max_anuncios),
			'valor_mensal' => round((float)$this->valor_mensal, 2),
			'ativo'        => (int)$this->ativo ? 1 : 0,
			'ordem'        => (int)$this->ordem,
		]);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'id'           => (int)$this->id,
			'slug'         => (string)$this->slug,
			'nome'         => (string)$this->nome,
			'descricao'    => (string)($this->descricao ?? ''),
			'maxAnuncios'  => (int)$this->max_anuncios,
			'valorMensal'  => round((float)$this->valor_mensal, 2),
			'ativo'        => (int)$this->ativo === 1,
			'ordem'        => (int)$this->ordem,
		];
	}
}
