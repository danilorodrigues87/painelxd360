<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class CrmTarefasCartoes{

	public
		$id,
		$lista_id,
		$titulo,
		$descricao,
		$posicao,
		$data_cadastro;

	public static function getCartaoById($id){
		return self::getCartoes('id = '.(int)$id)->fetchObject(self::class);
	}

	public static function getCartoes($where = null,$order = null,$limit = null,$fields = '*',$innerJoin = null){
		return (new Database('crm_tarefas_cartoes'))->select($where,$order,$limit,$fields,$innerJoin);
	}

	public static function getProximaPosicao($listaId){
		$row = self::getCartoes(
			'lista_id = '.(int)$listaId,
			'posicao DESC',
			1,
			'posicao'
		)->fetchObject(self::class);
		return $row ? ((int)$row->posicao + 1) : 0;
	}

	public function cadastrar(){
		$obDatabase = new Database('crm_tarefas_cartoes');
		$this->id = $obDatabase->insert([
			'lista_id'      => $this->lista_id,
			'titulo'        => $this->titulo,
			'descricao'     => $this->descricao ?? null,
			'posicao'       => $this->posicao ?? self::getProximaPosicao($this->lista_id),
			'data_cadastro' => $this->data_cadastro ?? date('Y-m-d H:i:s')
		]);
		return true;
	}

	public function atualizar(){
		return (new Database('crm_tarefas_cartoes'))->update('id = '.(int)$this->id,[
			'lista_id'  => $this->lista_id,
			'titulo'    => $this->titulo,
			'descricao' => $this->descricao,
			'posicao'   => $this->posicao
		]);
	}

	public function atualizarDescricao(){
		return (new Database('crm_tarefas_cartoes'))->update('id = '.(int)$this->id,[
			'descricao' => $this->descricao
		]);
	}

	public function atualizarPosicao(){
		return (new Database('crm_tarefas_cartoes'))->update('id = '.(int)$this->id,[
			'lista_id' => $this->lista_id,
			'posicao'  => $this->posicao
		]);
	}

	public static function excluir($id){
		return (new Database('crm_tarefas_cartoes'))->delete('id = '.(int)$id);
	}

}
