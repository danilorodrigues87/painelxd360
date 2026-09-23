<?php

namespace App\Model\Entity;
use App\Model\Db\Database;
use App\Model\Entity\Horarios;

class AgendaAula{

	public $id,
	$id_horario,
	$id_aluno,
	$id_trilha;

	//RETORNA COM BASE NO ID
	public static function getAgendaById($id){

		return self::getAgendamentos('id = '.$id)->fetchObject(self::class);

	}

	//ENVIA PARA O BANCO
	public function cadastrar(){
		
		//INSERIR OS DADOS PARA O BANCO DE DADOS
		$obDatabase = new Database('agenda_aula');
		$this->id = $obDatabase->insert([
			'id_horario' => $this->id_horario,
			'id_aluno' => $this->id_aluno,
			'id_trilha' => $this->id_trilha
		]);
		
		return true;
	} 

	//RETORNA A INFORMAÇÃO
	public static function getAgendamentos($where = null,$order = null,$limit = null,$fields = '*',$innerJoin = null){

		return (new Database('agenda_aula'))->select($where,$order,$limit,$fields,$innerJoin);
	}


	//ATUALIZA NO BANCO
	public function atualizar(){

		//ATUALIZA OS DADOS PARA O BANCO DE DADOS
		return (new Database('agenda_aula'))->update('id = '.$this->id,[
			'id_horario' => $this->id_horario,
			'id_aluno' => $this->id_aluno,
			'id_trilha' => $this->id_trilha
		]);

	}

	//EXCLUI DO BANCO DE DADOS
	public function excluir(){

	$dadosAgenda = (array) self::getAgendaById($this->id);
	$obHorarios = (array) Horarios::getHorarioById($dadosAgenda['id_horario']);
 
    $horarioAtual = new Horarios;
    $horarioAtual->id = $dadosAgenda['id_horario'];
    $horarioAtual->vagas_ocupadas = $obHorarios['vagas_ocupadas'] - 1;
    $horarioAtual->atualizarVaga();

	return (new Database('agenda_aula'))->delete('id = '.$this->id);

	}

}