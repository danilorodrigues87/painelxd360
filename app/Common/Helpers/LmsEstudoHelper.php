<?php

namespace App\Common\Helpers;

use App\Model\Db\Database;
use PDO;

/**
 * Tempo de estudo do aluno.
 * Preferência: heartbeat real (lms_estudo_sessao); fallback: duração das aulas concluídas.
 * Conquistas usam GREATEST(proxy, real) para não regredir.
 */
class LmsEstudoHelper {

	private const MAX_DELTA_SEC = 45;
	private const MAX_SESSION_SEC = 4 * 3600;
	private const MAX_DAY_SEC = 8 * 3600;
	private const OPEN_IDLE_SEC = 120;

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'lms_estudo_sessao'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/**
	 * Minutos totais para API / conquistas.
	 * GREATEST(proxy aulas concluídas, minutos reais do heartbeat).
	 */
	public static function minutosAluno(int $idAluno, int $idAdmin): int {
		if ($idAluno <= 0 || $idAdmin <= 0) {
			return 0;
		}
		$proxy = self::minutosProxyAulas($idAluno, $idAdmin);
		$real = self::minutosReais($idAluno, $idAdmin);
		return max($proxy, $real);
	}

	/** Soma minutos das aulas com concluida_em (duração dos vídeos; mín. 15/aula). */
	public static function minutosProxyAulas(int $idAluno, int $idAdmin): int {
		try {
			$sql = 'SELECT COALESCE(SUM(
					GREATEST(
						COALESCE((SELECT SUM(v.duracao_min) FROM lms_videos v WHERE v.id_aula = p.id_aula), 0),
						15
					)
				), 0) AS mins
				FROM lms_progresso_aula p
				WHERE p.id_aluno = '.(int)$idAluno.'
				AND p.id_admin = '.(int)$idAdmin.'
				AND p.concluida_em IS NOT NULL';
			$row = (new Database())->execute($sql)->fetch(PDO::FETCH_ASSOC);
			return (int)($row['mins'] ?? 0);
		} catch (\Throwable $e) {
			return 0;
		}
	}

	public static function minutosReais(int $idAluno, int $idAdmin): int {
		if (!self::tabelasExistem()) {
			return 0;
		}
		try {
			$row = (new Database('lms_estudo_sessao'))->select(
				'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin,
				null,
				null,
				'COALESCE(SUM(segundos), 0) AS sec'
			)->fetch(PDO::FETCH_ASSOC);
			return (int)floor(((int)($row['sec'] ?? 0)) / 60);
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/**
	 * Heartbeat do player. Retorna minutos totais (GREATEST proxy/real).
	 * @return array{ok:bool,totalMinutes:int,sessionSeconds:int,message?:string}
	 */
	public static function heartbeat(
		int $idAdmin,
		int $idAluno,
		int $idAula,
		?int $idCurso = null,
		string $origem = 'presence',
		?int $sessionId = null
	): array {
		if (!self::tabelasExistem()) {
			return [
				'ok' => false,
				'totalMinutes' => self::minutosAluno($idAluno, $idAdmin),
				'sessionSeconds' => 0,
				'message' => 'Execute database/lms_estudo_sessao.sql',
			];
		}
		if ($idAdmin <= 0 || $idAluno <= 0 || $idAula <= 0) {
			return ['ok' => false, 'totalMinutes' => 0, 'sessionSeconds' => 0, 'message' => 'Dados inválidos.'];
		}

		$origens = ['presence', 'youtube', 'private', 'bunny'];
		if (!in_array($origem, $origens, true)) {
			$origem = 'presence';
		}

		$now = date('Y-m-d H:i:s');
		$db = new Database('lms_estudo_sessao');
		$row = null;

		if ($sessionId !== null && $sessionId > 0) {
			$row = $db->select(
				'id = '.(int)$sessionId.' AND id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin.' AND ended_at IS NULL',
				null,
				'1'
			)->fetch(PDO::FETCH_ASSOC);
		}

		if (!$row) {
			$row = $db->select(
				'id_aluno = '.(int)$idAluno.' AND id_aula = '.(int)$idAula.' AND ended_at IS NULL
				 AND last_ping_at >= DATE_SUB(NOW(), INTERVAL '.(int)self::OPEN_IDLE_SEC.' SECOND)',
				'id DESC',
				'1'
			)->fetch(PDO::FETCH_ASSOC);
		}

		$delta = 0;
		if ($row) {
			$last = strtotime((string)$row['last_ping_at']);
			$elapsed = max(0, time() - $last);
			$delta = min($elapsed, self::MAX_DELTA_SEC);
			$novoSec = (int)$row['segundos'] + $delta;
			if ($novoSec > self::MAX_SESSION_SEC) {
				$delta = max(0, self::MAX_SESSION_SEC - (int)$row['segundos']);
				$novoSec = (int)$row['segundos'] + $delta;
			}
			$diaSec = self::segundosHoje($idAluno, $idAdmin);
			if ($diaSec + $delta > self::MAX_DAY_SEC) {
				$delta = max(0, self::MAX_DAY_SEC - $diaSec);
				$novoSec = (int)$row['segundos'] + $delta;
			}
			$db->update('id = '.(int)$row['id'], [
				'last_ping_at' => $now,
				'segundos' => $novoSec,
				'origem' => $origem,
			]);
			$sessionId = (int)$row['id'];
			$sessionSeconds = $novoSec;
		} else {
			$diaSec = self::segundosHoje($idAluno, $idAdmin);
			if ($diaSec >= self::MAX_DAY_SEC) {
				return [
					'ok' => true,
					'totalMinutes' => self::minutosAluno($idAluno, $idAdmin),
					'sessionSeconds' => 0,
					'sessionId' => null,
					'message' => 'Limite diário de estudo atingido.',
				];
			}
			$sessionId = (int)$db->insert([
				'id_aluno' => $idAluno,
				'id_admin' => $idAdmin,
				'id_aula' => $idAula,
				'id_curso' => $idCurso,
				'started_at' => $now,
				'last_ping_at' => $now,
				'ended_at' => null,
				'segundos' => 0,
				'origem' => $origem,
			]);
			$sessionSeconds = 0;
		}

		return [
			'ok' => true,
			'totalMinutes' => self::minutosAluno($idAluno, $idAdmin),
			'sessionSeconds' => $sessionSeconds,
			'sessionId' => $sessionId,
		];
	}

	private static function segundosHoje(int $idAluno, int $idAdmin): int {
		try {
			$row = (new Database('lms_estudo_sessao'))->select(
				'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin.'
				 AND DATE(started_at) = CURDATE()',
				null,
				null,
				'COALESCE(SUM(segundos), 0) AS sec'
			)->fetch(PDO::FETCH_ASSOC);
			return (int)($row['sec'] ?? 0);
		} catch (\Throwable $e) {
			return 0;
		}
	}
}
