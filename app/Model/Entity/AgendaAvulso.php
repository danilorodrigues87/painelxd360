<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class AgendaAvulso {

	public $id, $id_admin, $id_aluno, $matricula_id, $id_trilha, $id_horario,
		$data, $aulas_cota, $motivo, $ativo, $criado_por, $data_cadastro;

	public static function getById(int $id, ?int $idAdmin = null) {
		$where = 'id = '.(int)$id;
		if ($idAdmin !== null) {
			$where .= ' AND id_admin = '.(int)$idAdmin;
		}
		return self::getAll($where)->fetchObject(self::class);
	}

	public static function getAll($where = null, $order = null, $limit = null, $fields = '*', $innerJoin = null) {
		return (new Database('agenda_avulso'))->select($where, $order, $limit, $fields, $innerJoin);
	}

	public function cadastrar(): bool {
		$db = new Database('agenda_avulso');
		$this->id = $db->insert([
			'id_admin' => (int)$this->id_admin,
			'id_aluno' => (int)$this->id_aluno,
			'matricula_id' => (int)$this->matricula_id,
			'id_trilha' => (int)$this->id_trilha,
			'id_horario' => (int)$this->id_horario,
			'data' => $this->data,
			'aulas_cota' => max(1, min(10, (int)($this->aulas_cota ?? 2))),
			'motivo' => $this->motivo !== null && $this->motivo !== '' ? (string)$this->motivo : null,
			'ativo' => (int)($this->ativo ?? 1),
			'criado_por' => $this->criado_por ? (int)$this->criado_por : null,
		]);
		return true;
	}

	public function inativar(): bool {
		return (new Database('agenda_avulso'))->update('id = '.(int)$this->id, ['ativo' => 0]);
	}
}
