<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class CrmFunis{

	public
		$id,
		$id_admin,
		$nome,
		$ativo,
		$data_cadastro;

	public static function getFunilById($id){
		return self::getFunis('id = '.(int)$id)->fetchObject(self::class);
	}

	public static function getFunis($where = null, $order = null, $limit = null, $fields = '*'){
		return (new Database('crm_funis'))->select($where, $order, $limit, $fields);
	}

	public function cadastrar(){
		$obDatabase = new Database('crm_funis');
		$this->id = $obDatabase->insert([
			'id_admin'      => $this->id_admin,
			'nome'          => $this->nome,
			'ativo'         => $this->ativo ?? 1,
			'data_cadastro' => $this->data_cadastro ?? date('Y-m-d H:i:s')
		]);
		return true;
	}

	public function excluir(){
		return (new Database('crm_funis'))->delete('id = '.(int)$this->id);
	}

}
