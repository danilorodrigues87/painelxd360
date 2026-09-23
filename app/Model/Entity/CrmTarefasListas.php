<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class CrmTarefasListas{

	public
		$id,
		$id_admin,
		$titulo,
		$posicao,
		$data_cadastro;

	public static function getListaById($id, $id_admin = null){
		$where = 'id = '.(int)$id;
		if($id_admin !== null){
			$where .= ' AND id_admin = '.(int)$id_admin;
		}
		return self::getListas($where)->fetchObject(self::class);
	}

	public static function getListas($where = null,$order = null,$limit = null,$fields = '*',$innerJoin = null){
		return (new Database('crm_tarefas_listas'))->select($where,$order,$limit,$fields,$innerJoin);
	}

	public static function getProximaPosicao($id_admin){
		$row = self::getListas('id_admin = '.(int)$id_admin,'posicao DESC',1,'posicao')->fetchObject(self::class);
		return $row ? ((int)$row->posicao + 1) : 0;
	}

	public function cadastrar(){
		$obDatabase = new Database('crm_tarefas_listas');
		$this->id = $obDatabase->insert([
			'id_admin'      => (int)$this->id_admin,
			'titulo'        => $this->titulo,
			'posicao'       => $this->posicao ?? self::getProximaPosicao($this->id_admin),
			'data_cadastro' => $this->data_cadastro ?? date('Y-m-d H:i:s')
		]);
		return true;
	}

	public function atualizar(){
		return (new Database('crm_tarefas_listas'))->update('id = '.(int)$this->id,[
			'titulo'  => $this->titulo,
			'posicao' => $this->posicao
		]);
	}

	public static function excluir($id){
		return (new Database('crm_tarefas_listas'))->delete('id = '.(int)$id);
	}

}
