<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class AgendaAulas {

	public
		$id, $id_admin, $agenda_plano_id, $id_horario, $laboratorio_id,
		$id_aluno, $id_trilha, $data_aula, $status, $data_cadastro;

	public static function getById($id, $id_admin = null) {
		$where = 'id = '.(int)$id;
		if($id_admin !== null){
			$where .= ' AND id_admin = '.(int)$id_admin;
		}
		return self::getAulas($where)->fetchObject(self::class);
	}

	public static function getAulas($where = null, $order = null, $limit = null, $fields = '*', $innerJoin = null) {
		return (new Database('agenda_aulas'))->select($where, $order, $limit, $fields, $innerJoin);
	}

	public function cadastrar() {
		$obDatabase = new Database('agenda_aulas');
		$this->id = $obDatabase->insert([
			'id_admin'        => (int)$this->id_admin,
			'agenda_plano_id' => $this->agenda_plano_id,
			'id_horario'      => (int)$this->id_horario,
			'laboratorio_id'  => (int)$this->laboratorio_id,
			'id_aluno'        => (int)$this->id_aluno,
			'id_trilha'       => (int)$this->id_trilha,
			'data_aula'       => $this->data_aula,
			'status'          => $this->status ?? 'agendada'
		]);
		return true;
	}
}
