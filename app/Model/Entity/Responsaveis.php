<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class Responsaveis {

	public $id;
	public $nome;
	public $nascimento;
	public $rg;
	public $cpf;
	public $whatsapp;
	public $email;
	public $id_admin;


	//RETORNA UM DEPOIMENTO COM BASE NO ID
	public static function getResById($id){

		return self::getRes('id = '.$id)->fetchObject(self::class);

	}

	//ENVIA A MENSAGEM PARA O BANCO
	public function cadastrar(){
		
		//INSERIR OS DADOS PARA O BANCO DE DADOS
		$obDatabase = new Database('responsaveis');
		$this->id = $obDatabase->insert([
			'nome' => $this->nome,
			'email' => $this->email,
			'whatsapp' => $this->whatsapp,
			'rg' => $this->rg,
			'cpf' => $this->cpf,
			'nascimento' => $this->nascimento,
			'id_admin' => $this->id_admin
		]);
		
		return true;
	} 
 
	//RETORNA DEPOIMENTOS
	public static function getRes($where = null,$order = null,$limit = null,$fields = '*'){

		return (new Database('responsaveis'))->select($where,$order,$limit,$fields);
	}

	//ATUALIZA A MENSAGEM NO BANCO
	public function atualizar(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		return (new Database('responsaveis'))->update('id = '.$this->id,[
			'nome' => $this->nome,
			'email' => $this->email,
			'whatsapp' => $this->whatsapp,
			'rg' => $this->rg,
			'cpf' => $this->cpf,
			'nascimento' => $this->nascimento
		]);

	}


	//EXCLUI DO BANCO DE DADOS
	public function excluir(){

		return (new Database('responsaveis'))->delete('id = '.$this->id);

	}


}