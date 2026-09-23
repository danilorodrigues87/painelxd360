<?php
/*
namespace App\Model\Entity;
use App\Model\Db\Database;

class Carnes{

	public $id,$nome,$descricao;

	//RETORNA COM BASE NO ID
	public static function getItemById($id){

		return self::get('id = '.$id)->fetchObject(self::class);

	}

	//ENVIA PARA O BANCO
	public function cadastrar(){
		
		//INSERIR OS DADOS PARA O BANCO DE DADOS
		$obDatabase = new Database('');
		$this->id = $obDatabase->insert([
			'nome' => $this->nome,
			'descricao' => $this->descricao,
			'id_admin' => $this->id_admin
		]);
		
		return true;
	} 

	//RETORNA A INFORMAÇÃO
	public static function getCategory($where = null,$order = null,$limit = null,$fields = '*'){

		return (new Database('categorias_curso'))->select($where,$order,$limit,$fields);
	}

	//ATUALIZA NO BANCO
	public function atualizar(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		return (new Database('categorias_curso'))->update('id = '.$this->id,[
			'nome' => $this->nome,
			'descricao' => $this->descricao
		]);

	}

	//EXCLUI DO BANCO DE DADOS
	public function excluir(){

		return (new Database('categorias_curso'))->delete('id = '.$this->id);

	}

}
*/