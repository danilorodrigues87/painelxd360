<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class CaixaCarrinho{

	public 
		$id,
		$id_admin,
		$id_usuario,
		$referencia_id,
		$tipo,
		$descricao,
		$valor;

	//RETORNA COM BASE NO ID
	public static function getCaixaCarrinhoById($id){

		return self::getCaixaCarrinho('id = '.$id)->fetchObject(self::class);

	}

	//ENVIA PARA O BANCO
	public function cadastrar(){
		
		//INSERIR OS DADOS PARA O BANCO DE DADOS
		$obDatabase = new Database('caixa_carrinho');
		$this->id = $obDatabase->insert([
			'id_admin' => $this->id_admin,
			'id_usuario' => $this->id_usuario,
			'referencia_id' => $this->referencia_id,
			'tipo' => $this->tipo,
			'descricao' => $this->descricao,
			'valor' => $this->valor
		]);
		
		return true;
	} 

	//RETORNA A INFORMAÇÃO
	public static function getCaixaCarrinho($where = null,$order = null,$limit = null,$fields = '*',$innerJoin = null){

		return (new Database('caixa_carrinho'))->select($where,$order,$limit,$fields,$innerJoin);
	}

	//REMOVE UM ITEM ESPECÍFICO DO CARRINHO
	public static function deleteById($id,$id_admin,$id_usuario){
		return (new Database('caixa_carrinho'))->delete(
			'id = '.(int)$id.
			' AND id_admin = '.(int)$id_admin.
			' AND id_usuario = '.(int)$id_usuario
		);
	}

	//LIMPA TODO O CARRINHO DO USUÁRIO
	public static function clearByUser($id_admin,$id_usuario){
		return (new Database('caixa_carrinho'))->delete(
			'id_admin = '.(int)$id_admin.
			' AND id_usuario = '.(int)$id_usuario
		);
	}




}