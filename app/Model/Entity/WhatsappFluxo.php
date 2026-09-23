<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class WhatsappFluxo {

	public $id;
	public $id_admin;
	public $nome;
	public $ativo = 1;
	public $prioridade = 100;
	public $definicao;
	public $created_at;
	public $updated_at;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'whatsapp_fluxos'");
			$ok = (bool)$st->fetch();
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getByIdAdmin(int $id, int $idAdmin): ?self {
		if (!self::tabelaExiste()) {
			return null;
		}
		$ob = (new Database('whatsapp_fluxos'))
			->select('id = '.(int)$id.' AND id_admin = '.(int)$idAdmin, null, 1)
			->fetchObject(self::class);
		return $ob instanceof self ? $ob : null;
	}

	/** @return self[] */
	public static function listar(int $idAdmin): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$st = (new Database('whatsapp_fluxos'))
			->select('id_admin = '.(int)$idAdmin, 'prioridade ASC, id ASC');
		$out = [];
		while ($ob = $st->fetchObject(self::class)) {
			$out[] = $ob;
		}
		return $out;
	}

	/** @return self[] */
	public static function listarAtivos(int $idAdmin): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$st = (new Database('whatsapp_fluxos'))
			->select('id_admin = '.(int)$idAdmin.' AND ativo = 1', 'prioridade ASC, id ASC');
		$out = [];
		while ($ob = $st->fetchObject(self::class)) {
			$out[] = $ob;
		}
		return $out;
	}

	public function definicaoArray(): array {
		if (is_array($this->definicao)) {
			return $this->definicao;
		}
		$raw = (string)($this->definicao ?? '');
		if ($raw === '') {
			return [];
		}
		$j = json_decode($raw, true);
		return is_array($j) ? $j : [];
	}

	public function salvar(): bool {
		$db = new Database('whatsapp_fluxos');
		$def = $this->definicao;
		if (is_array($def)) {
			$def = json_encode($def, JSON_UNESCAPED_UNICODE);
		}
		$dados = [
			'id_admin'   => (int)$this->id_admin,
			'nome'       => mb_substr((string)$this->nome, 0, 120),
			'ativo'      => !empty($this->ativo) ? 1 : 0,
			'prioridade' => (int)$this->prioridade,
			'definicao'  => (string)$def,
		];
		if (!empty($this->id)) {
			return $db->update('id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin, $dados);
		}
		$this->id = (int)$db->insert($dados);
		return $this->id > 0;
	}

	public function excluir(): bool {
		if (empty($this->id)) {
			return false;
		}
		return (new Database('whatsapp_fluxos'))
			->delete('id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin);
	}
}
