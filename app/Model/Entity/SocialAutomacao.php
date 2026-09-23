<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class SocialAutomacao {

	public $id;
	public $id_admin;
	public $palavra_chave;
	public $match_modo = 'contem';
	public $mensagem_dm;
	public $canais = 'ambos';
	public $ativo = 1;
	public $created_at;
	public $updated_at;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'social_automacoes'");
			$ok = $st && $st->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getById(int $id, int $idAdmin): ?self {
		$row = (new Database('social_automacoes'))->select(
			'id = '.(int)$id.' AND id_admin = '.(int)$idAdmin
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return self[] */
	public static function listByAdmin(int $idAdmin, bool $somenteAtivas = false): array {
		$where = 'id_admin = '.(int)$idAdmin;
		if ($somenteAtivas) {
			$where .= ' AND ativo = 1';
		}
		$stmt = (new Database('social_automacoes'))->select($where, 'id ASC');
		$out = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$out[] = $r;
		}
		return $out;
	}

	public function salvar(): int {
		$modo = in_array($this->match_modo, ['contem', 'exato', 'inicia'], true)
			? $this->match_modo : 'contem';
		$canais = in_array($this->canais, ['instagram', 'facebook', 'ambos'], true)
			? $this->canais : 'ambos';
		$dados = [
			'id_admin' => (int)$this->id_admin,
			'palavra_chave' => mb_substr(trim((string)$this->palavra_chave), 0, 120),
			'match_modo' => $modo,
			'mensagem_dm' => (string)$this->mensagem_dm,
			'canais' => $canais,
			'ativo' => (int)$this->ativo ? 1 : 0,
		];
		$db = new Database('social_automacoes');
		if (!empty($this->id)) {
			$db->update('id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin, $dados);
			return (int)$this->id;
		}
		$this->id = (int)$db->insert($dados);
		return (int)$this->id;
	}

	public function excluir(): bool {
		return (bool)(new Database('social_automacoes'))->delete(
			'id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin
		);
	}

	public function bateCom(string $texto): bool {
		$kw = mb_strtolower(trim((string)$this->palavra_chave));
		$txt = mb_strtolower(trim($texto));
		if ($kw === '' || $txt === '') {
			return false;
		}
		if ($this->match_modo === 'exato') {
			return $txt === $kw;
		}
		if ($this->match_modo === 'inicia') {
			return mb_strpos($txt, $kw) === 0;
		}
		return mb_strpos($txt, $kw) !== false;
	}
}
