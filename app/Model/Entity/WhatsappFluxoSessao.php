<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class WhatsappFluxoSessao {

	public $id;
	public $id_admin;
	public $conversa_id;
	public $fluxo_id;
	public $node_atual = '';
	public $aguardando = 0;
	public $variaveis;
	public $passos = 0;
	public $updated_at;
	public $created_at;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'whatsapp_fluxo_sessoes'");
			$ok = (bool)$st->fetch();
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getByConversa(int $conversaId): ?self {
		if (!self::tabelaExiste()) {
			return null;
		}
		$ob = (new Database('whatsapp_fluxo_sessoes'))
			->select('conversa_id = '.(int)$conversaId, null, 1)
			->fetchObject(self::class);
		return $ob instanceof self ? $ob : null;
	}

	public function variaveisArray(): array {
		if (is_array($this->variaveis)) {
			return $this->variaveis;
		}
		$raw = (string)($this->variaveis ?? '');
		if ($raw === '') {
			return [];
		}
		$j = json_decode($raw, true);
		return is_array($j) ? $j : [];
	}

	public function salvar(): bool {
		$db = new Database('whatsapp_fluxo_sessoes');
		$vars = $this->variaveis;
		if (is_array($vars)) {
			$vars = json_encode($vars, JSON_UNESCAPED_UNICODE);
		}
		$dados = [
			'id_admin'    => (int)$this->id_admin,
			'conversa_id' => (int)$this->conversa_id,
			'fluxo_id'    => (int)$this->fluxo_id,
			'node_atual'  => mb_substr((string)$this->node_atual, 0, 64),
			'aguardando'  => !empty($this->aguardando) ? 1 : 0,
			'variaveis'   => $vars !== null && $vars !== '' ? (string)$vars : null,
			'passos'      => (int)$this->passos,
		];
		if (!empty($this->id)) {
			return $db->update('id = '.(int)$this->id, $dados);
		}
		$exist = self::getByConversa((int)$this->conversa_id);
		if ($exist) {
			$this->id = (int)$exist->id;
			return $db->update('id = '.(int)$this->id, $dados);
		}
		$this->id = (int)$db->insert($dados);
		return $this->id > 0;
	}

	public static function apagarPorConversa(int $conversaId): void {
		if (!self::tabelaExiste()) {
			return;
		}
		(new Database('whatsapp_fluxo_sessoes'))->delete('conversa_id = '.(int)$conversaId);
	}
}
