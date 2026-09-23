<?php

namespace App\Model\Entity\Estoque;

use App\Model\Db\Database;

class Stq_Categorias {

	public $id;
	public $nome;
	public $descricao;
	public $status;
	public $id_admin;
	public $created_at;
	public $updated_at;

	/* ==================================================
	 * BUSCAS
	 * ================================================== */

	// RETORNA COM BASE NO ID
	public static function getStqCategoriaById($id) {
		return self::getStqCategorias('id = '.$id)->fetchObject(self::class);
	}

	// RETORNA APENAS CATEGORIAS ATIVAS
	public static function getAtivas($order = null, $limit = null) {
		return self::getStqCategorias('status = 1', $order, $limit);
	}

	// RETORNA TODAS AS CATEGORIAS (GENÉRICO)
	public static function getStqCategorias(
		$where = null,
		$order = null,
		$limit = null,
		$fields = '*'
	) {
		return (new Database('stq_categorias'))
			->select($where, $order, $limit, $fields);
	}

	/* ==================================================
	 * CADASTRO / ATUALIZAÇÃO
	 * ================================================== */

	// CADASTRA NO BANCO
	public function cadastrar() {

		$obDatabase = new Database('stq_categorias');

		$this->id = $obDatabase->insert([
			'nome'       => $this->nome,
			'descricao'  => $this->descricao,
			'status'     => $this->status ?? 1,
			'id_admin'   => $this->id_admin,
			'created_at' => date('Y-m-d H:i:s')
		]);

		return true;
	}

	// ATUALIZA NO BANCO
	public function atualizar() {

		return (new Database('stq_categorias'))->update(
			'id = '.$this->id,
			[
				'nome'       => $this->nome,
				'descricao'  => $this->descricao,
				'status'     => $this->status,
				'updated_at' => date('Y-m-d H:i:s')
			]
		);
	}

	/* ==================================================
	 * STATUS (SOFT DELETE)
	 * ================================================== */

	public function inativar() {
		return (new Database('stq_categorias'))->update(
			'id = '.$this->id,
			[
				'status' => 0,
				'updated_at' => date('Y-m-d H:i:s')
			]
		);
	}

	public function ativar() {
		return (new Database('stq_categorias'))->update(
			'id = '.$this->id,
			[
				'status' => 1,
				'updated_at' => date('Y-m-d H:i:s')
			]
		);
	}
}
