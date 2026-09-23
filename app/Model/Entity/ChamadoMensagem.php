<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class ChamadoMensagem {

	public $id;
	public $chamado_id;
	public $autor_tipo;
	public $autor_id;
	public $mensagem;
	public $anexo_path;
	public $anexo_nome;
	public $created_at;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'chamado_mensagens'");
			$ok = (bool)$st->fetch();
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function get(string $where = null, string $order = null, $limit = null, string $fields = '*') {
		return (new Database('chamado_mensagens'))->select($where, $order, $limit, $fields);
	}

	public static function getById(int $id): ?self {
		$ob = self::get('id = '.(int)$id)->fetchObject(self::class);
		return $ob instanceof self ? $ob : null;
	}

	public static function listarPorChamado(int $chamadoId): array {
		$itens = [];
		$st = self::get('chamado_id = '.(int)$chamadoId, 'created_at ASC, id ASC');
		while ($ob = $st->fetchObject(self::class)) {
			$itens[] = $ob;
		}
		return $itens;
	}

	public function cadastrar(): bool {
		$db = new Database('chamado_mensagens');
		$this->id = (int)$db->insert([
			'chamado_id'  => (int)$this->chamado_id,
			'autor_tipo'  => $this->autor_tipo === 'master' ? 'master' : 'escola',
			'autor_id'    => (int)$this->autor_id,
			'mensagem'    => (string)$this->mensagem,
			'anexo_path'  => $this->anexo_path,
			'anexo_nome'  => $this->anexo_nome,
			'created_at'  => $this->created_at ?: date('Y-m-d H:i:s'),
		]);
		return $this->id > 0;
	}
}
