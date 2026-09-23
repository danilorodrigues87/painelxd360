<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class Laboratorios {

	public $id, $id_admin, $nome, $qtd_computadores, $ativo, $observacao, $data_cadastro;

	public static function getById($id, $id_admin = null) {
		$where = 'id = '.(int)$id;
		if($id_admin !== null){
			$where .= ' AND id_admin = '.(int)$id_admin;
		}
		return self::getLabs($where)->fetchObject(self::class);
	}

	public static function getLabs($where = null, $order = null, $limit = null, $fields = '*') {
		return (new Database('laboratorios'))->select($where, $order, $limit, $fields);
	}

	public function cadastrar() {
		$obDatabase = new Database('laboratorios');
		$this->id = $obDatabase->insert([
			'id_admin'         => (int)$this->id_admin,
			'nome'             => $this->nome,
			'qtd_computadores' => (int)$this->qtd_computadores,
			'ativo'            => (int)($this->ativo ?? 1),
			'observacao'       => $this->observacao
		]);
		return true;
	}

	public function atualizar() {
		return (new Database('laboratorios'))->update('id = '.(int)$this->id, [
			'nome'             => $this->nome,
			'qtd_computadores' => (int)$this->qtd_computadores,
			'ativo'            => (int)$this->ativo,
			'observacao'       => $this->observacao
		]);
	}

	public function excluir() {
		return (new Database('laboratorios'))->delete('id = '.(int)$this->id);
	}
}
