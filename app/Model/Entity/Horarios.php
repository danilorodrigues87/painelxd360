<?php

namespace App\Model\Entity;
use App\Model\Db\Database;

class Horarios{

	public $id,
	$id_admin,
	$laboratorio_id,
	$inicio,
	$final,
	$vagas_ocupadas,
	$dia_semana;

	//RETORNA COM BASE NO ID
	public static function getHorarioById($id){

		return self::getHorarios('id = '.$id)->fetchObject(self::class);

	}

	//ENVIA PARA O BANCO
	public function cadastrar(){
		
		//INSERIR OS DADOS PARA O BANCO DE DADOS
		$obDatabase = new Database('horarios');
		$this->id = $obDatabase->insert([
			'id_admin' => $this->id_admin,
			'laboratorio_id' => $this->laboratorio_id,
			'inicio' => $this->inicio,
			'final' => $this->final,
			'vagas_ocupadas' => $this->vagas_ocupadas,
			'dia_semana' => $this->dia_semana
		]);
		
		return true;
	} 

	//RETORNA A INFORMAÇÃO
	public static function getHorarios($where = null,$order = null,$limit = null,$fields = '*',$innerJoin = null){

		return (new Database('horarios'))->select($where,$order,$limit,$fields,$innerJoin);
	}

	//RETORNA A INFORMAÇÃO
	public static function getCustomTrilha($where = null){

		return (new Database())->customSelect($where);
	}

	//ATUALIZA NO BANCO
	public function atualizar(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		return (new Database('horarios'))->update('id = '.$this->id,[
			'laboratorio_id' => $this->laboratorio_id,
			'inicio' => $this->inicio,
			'final' => $this->final,
			'vagas_ocupadas' => $this->vagas_ocupadas,
			'dia_semana' => $this->dia_semana
		]);

	}

	//ATUALIZA VAGAS
	public function atualizarVaga(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		return (new Database('horarios'))->update('id = '.$this->id,[
			'vagas_ocupadas' => $this->vagas_ocupadas
		]);

	}


	//EXCLUI DO BANCO DE DADOS
	public function excluir(){

		return (new Database('horarios'))->delete('id = '.$this->id);

	}

}