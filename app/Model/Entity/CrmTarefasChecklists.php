<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class CrmTarefasChecklists{

	public
		$id,
		$cartao_id,
		$item_texto,
		$concluido;

	public static function getItemById($id){
		return self::getItens('id = '.(int)$id)->fetchObject(self::class);
	}

	public static function getItens($where = null,$order = null,$limit = null,$fields = '*',$innerJoin = null){
		return (new Database('crm_tarefas_checklists'))->select($where,$order,$limit,$fields,$innerJoin);
	}

	public static function getResumoPorCartao($cartaoId){
		$total = self::getItens('cartao_id = '.(int)$cartaoId,null,null,'COUNT(*) as total')->fetchObject();
		$concluidos = self::getItens('cartao_id = '.(int)$cartaoId.' AND concluido = 1',null,null,'COUNT(*) as total')->fetchObject();

		return [
			'total'      => (int)($total->total ?? 0),
			'concluidos' => (int)($concluidos->total ?? 0)
		];
	}

	public function cadastrar(){
		$obDatabase = new Database('crm_tarefas_checklists');
		$this->id = $obDatabase->insert([
			'cartao_id'  => $this->cartao_id,
			'item_texto' => $this->item_texto,
			'concluido'  => $this->concluido ?? 0
		]);
		return true;
	}

	public function atualizarConcluido(){
		return (new Database('crm_tarefas_checklists'))->update('id = '.(int)$this->id,[
			'concluido' => $this->concluido
		]);
	}

	public static function excluir($id){
		return (new Database('crm_tarefas_checklists'))->delete('id = '.(int)$id);
	}

}
