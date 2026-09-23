<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class CrmHistorico{

	public
		$id,
		$lead_id,
		$usuario_id,
		$acao,
		$observacao,
		$data_registro;

	public static function getHistoricoById($id){
		return self::getHistorico('id = '.(int)$id)->fetchObject(self::class);
	}

	public static function getHistorico($where = null,$order = null,$limit = null,$fields = '*',$innerJoin = null){
		return (new Database('crm_historico'))->select($where,$order,$limit,$fields,$innerJoin);
	}

	public function cadastrar(){
		$obDatabase = new Database('crm_historico');
		$this->id = $obDatabase->insert([
			'lead_id'       => $this->lead_id,
			'usuario_id'    => $this->usuario_id,
			'acao'          => $this->acao,
			'observacao'    => $this->observacao,
			'data_registro' => $this->data_registro ?? date('Y-m-d H:i:s')
		]);
		return true;
	}

}
