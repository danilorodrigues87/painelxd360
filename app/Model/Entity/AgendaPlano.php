<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class AgendaPlano {

	public
		$id, $id_admin, $matricula_id, $id_aluno, $id_trilha,
		$id_horario, $dia_semana, $ativo, $data_inicio, $data_fim, $data_cadastro;

	public static function getById($id, $id_admin = null) {
		$where = 'id = '.(int)$id;
		if($id_admin !== null){
			$where .= ' AND id_admin = '.(int)$id_admin;
		}
		return self::getPlanos($where)->fetchObject(self::class);
	}

	public static function getPlanos($where = null, $order = null, $limit = null, $fields = '*', $innerJoin = null) {
		return (new Database('agenda_plano'))->select($where, $order, $limit, $fields, $innerJoin);
	}

	public function cadastrar() {
		$obDatabase = new Database('agenda_plano');
		$this->id = $obDatabase->insert([
			'id_admin'     => (int)$this->id_admin,
			'matricula_id' => (int)$this->matricula_id,
			'id_aluno'     => (int)$this->id_aluno,
			'id_trilha'    => (int)$this->id_trilha,
			'id_horario'   => (int)$this->id_horario,
			'dia_semana'   => (int)$this->dia_semana,
			'ativo'        => (int)($this->ativo ?? 1),
			'data_inicio'  => $this->data_inicio ?? date('Y-m-d'),
			'data_fim'     => $this->data_fim
		]);
		return true;
	}

	public function inativar() {
		return (new Database('agenda_plano'))->update('id = '.(int)$this->id, [
			'ativo'    => 0,
			'data_fim' => date('Y-m-d')
		]);
	}

	/** Reabre plano soft-deleted (uk_plano_horario_aluno bloqueia novo INSERT no mesmo dia). */
	public function reativar(array $dados = []): bool {
		$upd = [
			'ativo' => 1,
			'data_fim' => null,
		];
		if (isset($dados['matricula_id'])) {
			$upd['matricula_id'] = (int)$dados['matricula_id'];
		}
		if (isset($dados['id_trilha'])) {
			$upd['id_trilha'] = (int)$dados['id_trilha'];
		}
		if (isset($dados['dia_semana'])) {
			$upd['dia_semana'] = (int)$dados['dia_semana'];
		}
		if (array_key_exists('data_inicio', $dados) && $dados['data_inicio']) {
			$upd['data_inicio'] = $dados['data_inicio'];
		}
		return (new Database('agenda_plano'))->update('id = '.(int)$this->id, $upd);
	}
}
