<?php

namespace App\Common\Helpers;

use App\Model\Db\Database;
use App\Model\Entity\Horarios;
use App\Model\Entity\AgendaPlano;
use App\Model\Entity\AgendaAulas;
use App\Model\Entity\Matriculas;
use App\Model\Entity\Laboratorios;
use PDO;

class AgendaHelper {

	private static $tabelaCache = [];
	private static $colunaCache = [];

	public static function tabelaExiste(string $table): bool {
		if (array_key_exists($table, self::$tabelaCache)) {
			return self::$tabelaCache[$table];
		}
		try {
			$row = (new Database())->execute("SHOW TABLES LIKE '".$table."'")->fetch(PDO::FETCH_NUM);
			self::$tabelaCache[$table] = (bool)$row;
		} catch (\Throwable $e) {
			self::$tabelaCache[$table] = false;
		}
		return self::$tabelaCache[$table];
	}

	public static function colunaExiste(string $table, string $column): bool {
		$key = $table.'.'.$column;
		if (array_key_exists($key, self::$colunaCache)) {
			return self::$colunaCache[$key];
		}
		if (!self::tabelaExiste($table)) {
			self::$colunaCache[$key] = false;
			return false;
		}
		try {
			$row = (new Database())->execute(
				"SHOW COLUMNS FROM `".$table."` LIKE '".$column."'"
			)->fetch(PDO::FETCH_NUM);
			self::$colunaCache[$key] = (bool)$row;
		} catch (\Throwable $e) {
			self::$colunaCache[$key] = false;
		}
		return self::$colunaCache[$key];
	}

	/** Cria tabela laboratorios e colunas laboratorio_id se ainda não existirem. */
	public static function garantirSchemaV2(): void {
		static $done = false;
		if ($done) {
			return;
		}

		$db = new Database();

		if (!self::tabelaExiste('laboratorios')) {
			$db->execute(
				'CREATE TABLE IF NOT EXISTS laboratorios (
					id INT UNSIGNED NOT NULL AUTO_INCREMENT,
					id_admin INT UNSIGNED NOT NULL,
					nome VARCHAR(255) NOT NULL,
					qtd_computadores INT UNSIGNED NOT NULL DEFAULT 10,
					ativo TINYINT(1) NOT NULL DEFAULT 1,
					observacao VARCHAR(500) NULL,
					data_cadastro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (id),
					KEY idx_laboratorios_admin (id_admin)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
			);
			self::$tabelaCache['laboratorios'] = true;
		}

		if (self::tabelaExiste('horarios') && !self::colunaExiste('horarios', 'laboratorio_id')) {
			$db->execute(
				'ALTER TABLE horarios ADD COLUMN laboratorio_id INT UNSIGNED NULL AFTER id_admin'
			);
			self::$colunaCache['horarios.laboratorio_id'] = true;
		}

		if (self::tabelaExiste('agenda_aulas') && !self::colunaExiste('agenda_aulas', 'laboratorio_id')) {
			$db->execute(
				'ALTER TABLE agenda_aulas ADD COLUMN laboratorio_id INT UNSIGNED NULL AFTER id_horario'
			);
			self::$colunaCache['agenda_aulas.laboratorio_id'] = true;
		}

		$done = true;
	}

	public static function garantirLabPadrao(int $id_admin): void {
		self::garantirSchemaV2();

		$total = (int)Laboratorios::getLabs(
			'id_admin = '.(int)$id_admin, null, null, 'COUNT(*) as qtd'
		)->fetchObject()->qtd;
		if ($total > 0) {
			return;
		}

		$ob = new Laboratorios;
		$ob->id_admin = $id_admin;
		$ob->nome = 'Laboratório Principal';
		$ob->qtd_computadores = 10;
		$ob->cadastrar();

		$labId = (int)$ob->id;
		$horarios = Horarios::getHorarios('id_admin = '.(int)$id_admin.' AND laboratorio_id IS NULL');
		while ($h = $horarios->fetchObject(Horarios::class)) {
			$upd = new Horarios;
			$upd->id = (int)$h->id;
			$upd->laboratorio_id = $labId;
			$upd->inicio = $h->inicio;
			$upd->final = $h->final;
			$upd->vagas_ocupadas = (int)$h->vagas_ocupadas;
			$upd->dia_semana = (int)$h->dia_semana;
			$upd->atualizar();
		}
	}

	public static function getCapacidadeHorario(int $idHorario): int {
		$row = Horarios::getHorarios(
			'horarios.id = '.(int)$idHorario,
			null, 1,
			'laboratorios.qtd_computadores',
			'INNER JOIN laboratorios ON laboratorios.id = horarios.laboratorio_id'
		)->fetch(PDO::FETCH_ASSOC);

		return (int)($row['qtd_computadores'] ?? 10);
	}

	public static function contarPlanosHorario(int $idHorario): int {
		return (int)AgendaPlano::getPlanos(
			'id_horario = '.(int)$idHorario.' AND ativo = 1',
			null, null, 'COUNT(*) as qtd'
		)->fetch(PDO::FETCH_ASSOC)['qtd'];
	}

	public static function recalcularVagasHorario(int $idHorario): void {
		$ocupadas = self::contarPlanosHorario($idHorario);
		$ob = new Horarios;
		$ob->id = $idHorario;
		$ob->vagas_ocupadas = $ocupadas;
		$ob->atualizarVaga();
	}

	public static function getMatriculaAtivaAluno(int $idAluno, int $idAdmin): ?array {
		$row = Matriculas::getMatriculas(
			'matriculas.id_aluno = '.(int)$idAluno.'
			AND matriculas.id_admin = '.(int)$idAdmin.'
			AND '.MatriculaStatusHelper::sqlAtiva('matriculas'),
			'id DESC', 1,
			'matriculas.id, matriculas.aulas_semanais, matriculas.id_trilha'
		)->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	public static function contarPlanosAluno(int $idAluno, int $idAdmin): int {
		return (int)AgendaPlano::getPlanos(
			'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin.' AND ativo = 1',
			null, null, 'COUNT(*) as qtd'
		)->fetch(PDO::FETCH_ASSOC)['qtd'];
	}

	public static function diaSemanaData(string $data): int {
		$dia = (int)date('w', strtotime($data));
		return $dia === 0 ? 1 : $dia;
	}

	public static function gerarAulasDia(int $idAdmin, string $data): int {
		$diaSemana = self::diaSemanaData($data);
		$geradas = 0;

		$planos = AgendaPlano::getPlanos(
			'agenda_plano.id_admin = '.(int)$idAdmin.'
			AND agenda_plano.ativo = 1
			AND agenda_plano.dia_semana = '.(int)$diaSemana.'
			AND agenda_plano.data_inicio <= "'.$data.'"
			AND (agenda_plano.data_fim IS NULL OR agenda_plano.data_fim >= "'.$data.'")',
			null, null,
			'agenda_plano.*, horarios.laboratorio_id',
			'INNER JOIN horarios ON horarios.id = agenda_plano.id_horario'
		);

		while ($plano = $planos->fetch(PDO::FETCH_ASSOC)) {
			$existe = AgendaAulas::getAulas(
				'id_aluno = '.(int)$plano['id_aluno'].'
				AND id_horario = '.(int)$plano['id_horario'].'
				AND data_aula = "'.$data.'"',
				null, 1, 'id'
			)->fetch(PDO::FETCH_ASSOC);

			if($existe){
				continue;
			}

			$ob = new AgendaAulas;
			$ob->id_admin = $idAdmin;
			$ob->agenda_plano_id = (int)$plano['id'];
			$ob->id_horario = (int)$plano['id_horario'];
			$ob->laboratorio_id = (int)$plano['laboratorio_id'];
			$ob->id_aluno = (int)$plano['id_aluno'];
			$ob->id_trilha = (int)$plano['id_trilha'];
			$ob->data_aula = $data;
			$ob->status = 'agendada';
			$ob->cadastrar();
			$geradas++;
		}

		return $geradas;
	}

	public static function migrarLegado(int $idAdmin): int {
		if (!self::tabelaExiste('agenda_aula')) {
			return 0;
		}

		$migrados = 0;

		$results = (new Database('agenda_aula'))->select(
			'agenda_aula.id_horario IN (SELECT id FROM horarios WHERE id_admin = '.(int)$idAdmin.')',
			'id ASC'
		);

		while ($row = $results->fetch(PDO::FETCH_ASSOC)) {
			$matricula = self::getMatriculaAtivaAluno((int)$row['id_aluno'], $idAdmin);
			if(!$matricula){
				continue;
			}

			$horario = Horarios::getHorarioById((int)$row['id_horario']);
			if(!$horario){
				continue;
			}

			$planoExistente = AgendaPlano::getPlanos(
				'id_admin = '.(int)$idAdmin.'
				AND id_aluno = '.(int)$row['id_aluno'].'
				AND id_horario = '.(int)$row['id_horario'],
				'id DESC',
				1,
				'id, ativo'
			)->fetch(PDO::FETCH_ASSOC);

			if ($planoExistente) {
				continue;
			}

			$dataInicio = date('Y-m-d');

			$ob = new AgendaPlano;
			$ob->id_admin = $idAdmin;
			$ob->matricula_id = (int)$matricula['id'];
			$ob->id_aluno = (int)$row['id_aluno'];
			$ob->id_trilha = (int)$row['id_trilha'];
			$ob->id_horario = (int)$row['id_horario'];
			$ob->dia_semana = (int)$horario->dia_semana;
			$ob->data_inicio = $dataInicio;
			$ob->cadastrar();

			self::recalcularVagasHorario((int)$row['id_horario']);
			$migrados++;
		}

		return $migrados;
	}

}
