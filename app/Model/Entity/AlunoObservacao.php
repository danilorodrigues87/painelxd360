<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class AlunoObservacao {

	public $id;
	public $id_admin;
	public $aluno_id;
	public $usuario_id;
	public $observacao;
	public $criado_em;

	public static function tabelaExiste(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('aluno_observacoes'))->execute(
				"SHOW TABLES LIKE 'aluno_observacoes'"
			)->fetch(\PDO::FETCH_NUM);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function get($where = null, $order = null, $limit = null, $fields = '*') {
		return (new Database('aluno_observacoes'))->select($where, $order, $limit, $fields);
	}

	public function cadastrar(): bool {
		$this->id = (new Database('aluno_observacoes'))->insert([
			'id_admin'   => (int)$this->id_admin,
			'aluno_id'   => (int)$this->aluno_id,
			'usuario_id' => (int)$this->usuario_id,
			'observacao' => $this->observacao,
			'criado_em'  => $this->criado_em ?? date('Y-m-d H:i:s'),
		]);
		return (int)$this->id > 0;
	}
}
