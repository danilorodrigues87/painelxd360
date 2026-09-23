<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class CrmTarefasComentarios{

	public
		$id,
		$cartao_id,
		$usuario_id,
		$comentario,
		$data_cadastro;

	public static function getComentarios($where = null,$order = null,$limit = null,$fields = '*',$innerJoin = null){
		return (new Database('crm_tarefas_comentarios'))->select($where,$order,$limit,$fields,$innerJoin);
	}

	public function cadastrar(){
		$obDatabase = new Database('crm_tarefas_comentarios');
		$this->id = $obDatabase->insert([
			'cartao_id'     => $this->cartao_id,
			'usuario_id'    => $this->usuario_id,
			'comentario'    => $this->comentario,
			'data_cadastro' => $this->data_cadastro ?? date('Y-m-d H:i:s')
		]);
		return true;
	}

}
