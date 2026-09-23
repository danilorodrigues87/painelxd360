<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class Chamado {

	public $id;
	public $numero;
	public $id_admin;
	public $usuario_id;
	public $categoria;
	public $assunto;
	public $status;
	public $prioridade;
	public $created_at;
	public $updated_at;
	public $fechado_em;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'chamados'");
			$ok = (bool)$st->fetch();
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function get(string $where = null, string $order = null, $limit = null, string $fields = '*') {
		return (new Database('chamados'))->select($where, $order, $limit, $fields);
	}

	public static function getById(int $id): ?self {
		$ob = self::get('id = '.(int)$id)->fetchObject(self::class);
		return $ob instanceof self ? $ob : null;
	}

	public static function getByIdAdmin(int $id, int $idAdmin): ?self {
		$ob = self::get('id = '.(int)$id.' AND id_admin = '.(int)$idAdmin)->fetchObject(self::class);
		return $ob instanceof self ? $ob : null;
	}

	public static function getByNumero(string $numero): ?self {
		$numero = trim($numero);
		if ($numero === '') {
			return null;
		}
		$db = new Database('chamados');
		$st = $db->execute('SELECT * FROM chamados WHERE numero = :n LIMIT 1', [':n' => $numero]);
		$ob = $st->fetchObject(self::class);
		return $ob instanceof self ? $ob : null;
	}

	public function cadastrar(): bool {
		$db = new Database('chamados');
		$this->id = (int)$db->insert([
			'numero'      => $this->numero ?: ('TMP-'.bin2hex(random_bytes(4))),
			'id_admin'    => (int)$this->id_admin,
			'usuario_id'  => (int)$this->usuario_id,
			'categoria'   => $this->categoria ?: 'duvida',
			'assunto'     => $this->assunto,
			'status'      => $this->status ?: 'aberto',
			'prioridade'  => $this->prioridade ?: 'normal',
			'created_at'  => $this->created_at ?: date('Y-m-d H:i:s'),
			'updated_at'  => $this->updated_at ?: date('Y-m-d H:i:s'),
			'fechado_em'  => $this->fechado_em,
		]);
		if ($this->id <= 0) {
			return false;
		}
		$this->numero = 'CHM-'.date('Y').'-'.str_pad((string)$this->id, 5, '0', STR_PAD_LEFT);
		$db->update('id = '.$this->id, ['numero' => $this->numero]);
		return true;
	}

	public function atualizarStatus(string $status): bool {
		$this->status = $status;
		$dados = [
			'status'     => $status,
			'updated_at' => date('Y-m-d H:i:s'),
		];
		if (in_array($status, ['resolvido', 'fechado'], true)) {
			$dados['fechado_em'] = date('Y-m-d H:i:s');
			$this->fechado_em = $dados['fechado_em'];
		} else {
			$dados['fechado_em'] = null;
			$this->fechado_em = null;
		}
		return (new Database('chamados'))->update('id = '.(int)$this->id, $dados);
	}

	public function tocarUpdatedAt(): bool {
		return (new Database('chamados'))->update('id = '.(int)$this->id, [
			'updated_at' => date('Y-m-d H:i:s'),
		]);
	}

	public static function contarAbertos(): int {
		if (!self::tabelaExiste()) {
			return 0;
		}
		$row = self::get(
			"status IN ('aberto','em_andamento')",
			null,
			null,
			'COUNT(*) AS total'
		)->fetch(\PDO::FETCH_ASSOC);
		return (int)($row['total'] ?? 0);
	}
}
