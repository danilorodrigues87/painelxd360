<?php

namespace App\Model\Entity\Estoque;

use App\Model\Db\Database;

class Stq_VendaItens {

	public $id;
	public $id_venda;
	public $id_produto;
	public $nome_snapshot;
	public $qtd;
	public $valor_unitario;
	public $subtotal;

	public function cadastrar(): bool {
		$this->id = (new Database('stq_venda_itens'))->insert([
			'id_venda' => (int)$this->id_venda,
			'id_produto' => (int)$this->id_produto,
			'nome_snapshot' => (string)$this->nome_snapshot,
			'qtd' => (int)$this->qtd,
			'valor_unitario' => (float)$this->valor_unitario,
			'subtotal' => (float)$this->subtotal,
		]);
		return (int)$this->id > 0;
	}

	public static function getByVenda(int $idVenda) {
		return (new Database('stq_venda_itens'))->select(
			'id_venda = ' . (int)$idVenda,
			'id ASC'
		);
	}
}
