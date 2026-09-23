<?php

namespace App\Model\Entity\Estoque;

use App\Model\Db\Database;

class Stq_Vendas {

	public $id;
	public $id_admin;
	public $id_usuario;
	public $total;
	public $tipo_pagamento;
	public $id_caixa;
	public $observacao;
	public $created_at;

	public static function getById(int $id, ?int $idAdmin = null) {
		$where = 'id = ' . (int)$id;
		if ($idAdmin !== null) {
			$where .= ' AND id_admin = ' . (int)$idAdmin;
		}
		return (new Database('stq_vendas'))
			->select($where)
			->fetchObject(self::class);
	}

	public static function getAll($where = null, $order = 'id DESC', $limit = null, $fields = '*') {
		return (new Database('stq_vendas'))->select($where, $order, $limit, $fields);
	}

	public function cadastrar(): bool {
		$this->id = (new Database('stq_vendas'))->insert([
			'id_admin' => (int)$this->id_admin,
			'id_usuario' => $this->id_usuario !== null ? (int)$this->id_usuario : null,
			'total' => (float)$this->total,
			'tipo_pagamento' => (string)$this->tipo_pagamento,
			'id_caixa' => $this->id_caixa !== null ? (int)$this->id_caixa : null,
			'observacao' => $this->observacao,
			'created_at' => date('Y-m-d H:i:s'),
		]);
		return (int)$this->id > 0;
	}

	public function atualizarCaixa(int $idCaixa): bool {
		return (new Database('stq_vendas'))->update(
			'id = ' . (int)$this->id,
			['id_caixa' => $idCaixa]
		);
	}
}
