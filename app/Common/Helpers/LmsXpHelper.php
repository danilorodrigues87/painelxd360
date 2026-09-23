<?php

namespace App\Common\Helpers;

use App\Model\Entity\LmsXpLedger;
use App\Model\Entity\User;
use App\Common\Helpers\UserFotoHelper;

/**
 * XP / níveis / ranking por escola (id_admin).
 */
class LmsXpHelper {

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new \App\Model\Db\Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_xp_ledger'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function levelFromXp(int $xp): int {
		return (int)floor(sqrt(max(0, $xp) / 50)) + 1;
	}

	public static function xpForNextLevel(int $level): int {
		$level = max(1, $level);
		return (int)(($level * $level) * 50);
	}

	/** Credita XP uma vez por (aluno, fonte, id_ref). Retorna xp creditado (0 se já existia). */
	public static function credit(int $idAdmin, int $idAluno, string $fonte, string $idRef, int $xp): int {
		if (!self::tabelasExistem() || $xp <= 0 || $idAluno <= 0) {
			return 0;
		}
		$existing = LmsXpLedger::getByUnique($idAluno, $fonte, $idRef);
		if ($existing) {
			return 0;
		}
		$row = new LmsXpLedger();
		$row->id_admin = $idAdmin;
		$row->id_aluno = $idAluno;
		$row->fonte = $fonte;
		$row->id_ref = $idRef;
		$row->xp = $xp;
		$row->salvar();
		return $xp;
	}

	public static function totalAluno(int $idAluno, int $idAdmin): int {
		if (!self::tabelasExistem()) {
			return 0;
		}
		return LmsXpLedger::sumByAluno($idAluno, $idAdmin);
	}

	public static function creditLessonComplete(int $idAdmin, int $idAluno, int $idAula, int $duracaoMin): int {
		// 5–20 XP (antes 10–40)
		$xp = 5 + min(max(0, $duracaoMin), 15);
		return self::credit($idAdmin, $idAluno, 'lesson_complete', (string)$idAula, $xp);
	}

	public static function creditAssessment(int $idAdmin, int $idAluno, int $idAtividade, float $score, bool $passed): int {
		if ($passed) {
			// 15–35 XP (antes 30–70)
			$xp = 15 + (int)round(20 * ($score / 100));
			return self::credit($idAdmin, $idAluno, 'assessment_pass', (string)$idAtividade, $xp);
		}
		return self::credit($idAdmin, $idAluno, 'assessment_attempt', (string)$idAtividade, 2);
	}

	public static function creditRoleplay(int $idAdmin, int $idAluno, int $idSessao, float $score): int {
		// ~20–35 XP (antes ~40–70)
		$xp = 20 + (int)round($score * 0.15);
		return self::credit($idAdmin, $idAluno, 'roleplay_complete', (string)$idSessao, $xp);
	}

	public static function creditDailyStreak(int $idAdmin, int $idAluno): int {
		$ref = date('Y-m-d');
		return self::credit($idAdmin, $idAluno, 'streak_daily', $ref, 3);
	}

	/** Ranking da escola: top N + posição do aluno. */
	public static function rankingEscola(int $idAdmin, int $idAluno, int $limit = 20): array {
		if (!self::tabelasExistem()) {
			return ['entries' => [], 'me' => null, 'scope' => 'school'];
		}
		return self::montarRanking(LmsXpLedger::rankingByAdmin($idAdmin, 500), $idAluno, $limit, 'school');
	}

	/**
	 * Ranking global: XP dos últimos 30 dias (evita vantagem de escolas com mais conteúdo).
	 */
	public static function rankingGlobal(int $idAluno, int $limit = 50): array {
		if (!self::tabelasExistem()) {
			return ['entries' => [], 'me' => null, 'scope' => 'global', 'periodDays' => 30];
		}
		$data = self::montarRanking(LmsXpLedger::rankingGlobalPeriodo(30, 500), $idAluno, $limit, 'global');
		$data['periodDays'] = 30;
		return $data;
	}

	/**
	 * @param array<int,array{id_aluno:mixed,xp_total:mixed}> $rows
	 */
	private static function montarRanking(array $rows, int $idAluno, int $limit, string $scope): array {
		$entries = [];
		$me = null;
		$pos = 0;
		foreach ($rows as $r) {
			$pos++;
			$user = User::getUserById((int)$r['id_aluno']);
			$foto = ($user && User::temColunaFoto()) ? trim((string)($user->foto ?? '')) : '';
			$avatar = ($foto !== '' && strpos($foto, '..') === false && strpos($foto, '/') === false)
				? UserFotoHelper::urlPublica($foto)
				: null;
			$city = $user ? StudentApiMapper::cidadeLabel($user->cidade ?? null, $user->uf ?? null) : null;
			$xpRank = (int)$r['xp_total'];
			// Global usa XP da janela; nível exibido continua o da carreira (XP total)
			if ($scope === 'global' && $user) {
				$level = self::levelFromXp(self::totalAluno((int)$r['id_aluno'], (int)$user->id_admin));
			} else {
				$level = self::levelFromXp($xpRank);
			}
			$entry = [
				'id' => (string)$r['id_aluno'],
				'name' => $user ? (string)$user->nome : 'Aluno',
				'avatarUrl' => $avatar,
				'city' => $city,
				'xp' => $xpRank,
				'level' => $level,
				'position' => $pos,
				'isCurrentUser' => ((int)$r['id_aluno'] === $idAluno),
			];
			if ((int)$r['id_aluno'] === $idAluno) {
				$me = $entry;
			}
			if ($pos <= $limit) {
				$entries[] = $entry;
			}
		}
		return ['entries' => $entries, 'me' => $me, 'scope' => $scope];
	}
}
