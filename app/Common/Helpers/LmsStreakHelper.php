<?php

namespace App\Common\Helpers;

use App\Model\Entity\AgendaPlano;
use App\Model\Entity\AgendaAvulso;
use App\Model\Entity\AgendaAulas;
use App\Model\Db\Database;
use PDO;

/**
 * Sequência de sessões de agenda cumpridas (não dias corridos de login).
 * Só contam dias em que o aluno tinha plano/avulso; dias sem agenda não quebram a sequência.
 */
class LmsStreakHelper {

	public const LOOKBACK_DAYS = 90;

	/**
	 * Quantas sessões de agenda consecutivas (para trás) o aluno cumpriu.
	 * Hoje agendado e ainda não cumprido: ignora o dia (não zera a sequência).
	 */
	public static function streakDays(int $idAluno, int $idAdmin): int {
		if ($idAluno <= 0 || $idAdmin <= 0) {
			return 0;
		}
		$datas = self::datasAgendadasRecentes($idAluno, $idAdmin);
		if (!$datas) {
			return 0;
		}
		$hoje = LmsAgendaAcessoHelper::agora()->format('Y-m-d');
		$streak = 0;
		foreach ($datas as $data) {
			$cumprido = self::diaCumprido($idAluno, $idAdmin, $data);
			if ($data === $hoje && !$cumprido) {
				continue;
			}
			if ($cumprido) {
				$streak++;
				continue;
			}
			break;
		}
		return $streak;
	}

	/**
	 * Credita +5 XP de streak só se hoje for dia de agenda e estiver cumprido.
	 */
	public static function creditXpSeSessaoHoje(int $idAdmin, int $idAluno): int {
		$hoje = LmsAgendaAcessoHelper::agora()->format('Y-m-d');
		if (!self::temAgendaNoDia($idAluno, $idAdmin, $hoje)) {
			return 0;
		}
		if (!self::diaCumprido($idAluno, $idAdmin, $hoje)) {
			return 0;
		}
		return LmsXpHelper::creditDailyStreak($idAdmin, $idAluno);
	}

	/** @return list<string> Y-m-d decrescente */
	public static function datasAgendadasRecentes(int $idAluno, int $idAdmin): array {
		$planos = self::planosAtivos($idAluno, $idAdmin);
		$avulsoSet = self::datasAvulso($idAluno, $idAdmin);

		$now = LmsAgendaAcessoHelper::agora();
		$out = [];
		for ($i = 0; $i < self::LOOKBACK_DAYS; $i++) {
			$d = $now->modify('-'.$i.' days');
			$data = $d->format('Y-m-d');
			if (isset($avulsoSet[$data]) || self::planoCobreData($planos, $data, LmsAgendaAcessoHelper::diaSemanaHoje($d))) {
				$out[] = $data;
			}
		}
		return $out;
	}

	public static function temAgendaNoDia(int $idAluno, int $idAdmin, string $data): bool {
		$planos = self::planosAtivos($idAluno, $idAdmin);
		$w = (int)date('w', strtotime($data.' 12:00:00'));
		$diaSemana = $w === 0 ? 1 : $w;
		if (self::planoCobreData($planos, $data, $diaSemana)) {
			return true;
		}
		$avulso = self::datasAvulso($idAluno, $idAdmin);
		return isset($avulso[$data]);
	}

	public static function diaCumprido(int $idAluno, int $idAdmin, string $data): bool {
		if (self::tevePresenca($idAluno, $idAdmin, $data)) {
			return true;
		}
		if (self::teveAtividadeLms($idAluno, $idAdmin, $data)) {
			return true;
		}
		return false;
	}

	/** @return list<array{dia_semana:int,data_inicio:string,data_fim:?string}> */
	private static function planosAtivos(int $idAluno, int $idAdmin): array {
		static $cache = [];
		$key = $idAluno.':'.$idAdmin;
		if (isset($cache[$key])) {
			return $cache[$key];
		}
		$rows = [];
		$stmt = AgendaPlano::getPlanos(
			'id_admin = '.(int)$idAdmin.' AND id_aluno = '.(int)$idAluno.' AND ativo = 1',
			null, null,
			'dia_semana, data_inicio, data_fim'
		);
		while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$rows[] = [
				'dia_semana' => (int)$r['dia_semana'],
				'data_inicio' => (string)$r['data_inicio'],
				'data_fim' => !empty($r['data_fim']) ? (string)$r['data_fim'] : null,
			];
		}
		return $cache[$key] = $rows;
	}

	/** @return array<string,true> */
	private static function datasAvulso(int $idAluno, int $idAdmin): array {
		static $cache = [];
		$key = $idAluno.':'.$idAdmin;
		if (isset($cache[$key])) {
			return $cache[$key];
		}
		$set = [];
		if (!LmsAgendaAcessoHelper::tabelasExistem()) {
			return $cache[$key] = $set;
		}
		$desde = LmsAgendaAcessoHelper::agora()->modify('-'.self::LOOKBACK_DAYS.' days')->format('Y-m-d');
		$stmt = AgendaAvulso::getAll(
			'id_admin = '.(int)$idAdmin.' AND id_aluno = '.(int)$idAluno.' AND ativo = 1 AND data >= "'.$desde.'"',
			null, null, 'data'
		);
		while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$set[(string)$r['data']] = true;
		}
		return $cache[$key] = $set;
	}

	/** @param list<array{dia_semana:int,data_inicio:string,data_fim:?string}> $planos */
	private static function planoCobreData(array $planos, string $data, int $diaSemana): bool {
		foreach ($planos as $p) {
			if ((int)$p['dia_semana'] !== $diaSemana) {
				continue;
			}
			if ($p['data_inicio'] > $data) {
				continue;
			}
			if ($p['data_fim'] !== null && $p['data_fim'] < $data) {
				continue;
			}
			return true;
		}
		return false;
	}

	private static function tevePresenca(int $idAluno, int $idAdmin, string $data): bool {
		try {
			$row = AgendaAulas::getAulas(
				'agenda_aulas.id_admin = '.(int)$idAdmin.'
				AND agenda_aulas.id_aluno = '.(int)$idAluno.'
				AND agenda_aulas.data_aula = "'.addslashes($data).'"
				AND presencas.status IN ("presente","reposicao")',
				null, '1',
				'agenda_aulas.id',
				'INNER JOIN presencas ON presencas.agenda_aula_id = agenda_aulas.id'
			)->fetch(PDO::FETCH_ASSOC);
			return !empty($row);
		} catch (\Throwable $e) {
			return false;
		}
	}

	private static function teveAtividadeLms(int $idAluno, int $idAdmin, string $data): bool {
		try {
			$db = new Database();
			if (LmsAgendaAcessoHelper::tabelasExistem()) {
				$cota = $db->execute(
					'SELECT id FROM lms_sessao_cota
					 WHERE id_admin = ? AND id_aluno = ? AND data = ?
					 AND aulas_ids IS NOT NULL AND aulas_ids != "" AND aulas_ids != "[]"
					 LIMIT 1',
					[$idAdmin, $idAluno, $data]
				)->fetch(PDO::FETCH_ASSOC);
				if ($cota) {
					return true;
				}
			}
			$prog = $db->execute(
				'SELECT id FROM lms_progresso_aula
				 WHERE id_admin = ? AND id_aluno = ?
				 AND (
				   DATE(ultimo_acesso) = ?
				   OR DATE(concluida_em) = ?
				 )
				 LIMIT 1',
				[$idAdmin, $idAluno, $data, $data]
			)->fetch(PDO::FETCH_ASSOC);
			if ($prog) {
				return true;
			}
			if (LmsXpHelper::tabelasExistem()) {
				$xp = $db->execute(
					'SELECT id FROM lms_xp_ledger
					 WHERE id_admin = ? AND id_aluno = ? AND DATE(created_at) = ?
					 AND fonte != "streak_daily"
					 LIMIT 1',
					[$idAdmin, $idAluno, $data]
				)->fetch(PDO::FETCH_ASSOC);
				if ($xp) {
					return true;
				}
			}
		} catch (\Throwable $e) {
			return false;
		}
		return false;
	}
}
