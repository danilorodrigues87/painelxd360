<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class Presencas {

	public
		$id, $id_admin, $agenda_aula_id, $id_aluno,
		$status, $observacao, $registrado_por, $data_registro;

	public static function getByAulaId($agendaAulaId) {
		return self::getPresencas('agenda_aula_id = '.(int)$agendaAulaId)->fetchObject(self::class);
	}

	public static function getPresencas($where = null, $order = null, $limit = null, $fields = '*', $innerJoin = null) {
		return (new Database('presencas'))->select($where, $order, $limit, $fields, $innerJoin);
	}

	public function salvar() {
		$existe = self::getByAulaId((int)$this->agenda_aula_id);

		if($existe instanceof self){
			$this->id = $existe->id;
			return (new Database('presencas'))->update('id = '.(int)$this->id, [
				'status'          => $this->status,
				'observacao'      => $this->observacao,
				'registrado_por'  => (int)$this->registrado_por,
				'data_registro'   => date('Y-m-d H:i:s')
			]);
		}

		$obDatabase = new Database('presencas');
		$this->id = $obDatabase->insert([
			'id_admin'        => (int)$this->id_admin,
			'agenda_aula_id'  => (int)$this->agenda_aula_id,
			'id_aluno'        => (int)$this->id_aluno,
			'status'          => $this->status,
			'observacao'      => $this->observacao,
			'registrado_por'  => (int)$this->registrado_por
		]);
		return true;
	}
}
