<?php

namespace App\Common\Helpers;

use App\Model\Entity\LmsCertificado;
use App\Model\Entity\LmsConquistaAluno;
use App\Model\Entity\LmsConquistaDef;
use App\Model\Entity\LmsRankingDiario;
use App\Model\Entity\LmsRoleplaySessao;
use App\Model\Entity\User;
use App\Model\Db\Database;
use PDO;
// BrandingHelper e LmsNotificacaoHelper: mesmo namespace

/**
 * Conquistas / metas do portal EAD.
 */
class LmsConquistaHelper {

	public static function tabelasExistem(): bool {
		return LmsConquistaDef::tabelasExistem();
	}

	/** Recalcula progresso e desbloqueios do aluno. Idempotente. Preserva origem=manual. */
	public static function recalcular(int $idAdmin, int $idAluno): void {
		if (!self::tabelasExistem() || $idAdmin <= 0 || $idAluno <= 0) {
			return;
		}

		$defs = LmsConquistaDef::listAtivasParaEscola($idAdmin);
		if (!$defs) {
			return;
		}

		// conquistas_unlocked por último (conta desbloqueios desta passada)
		usort($defs, static function ($a, $b) {
			$aw = ((string)$a->meta_tipo === 'conquistas_unlocked') ? 1 : 0;
			$bw = ((string)$b->meta_tipo === 'conquistas_unlocked') ? 1 : 0;
			if ($aw !== $bw) {
				return $aw <=> $bw;
			}
			return ((int)$a->ordem) <=> ((int)$b->ordem);
		});

		$stats = self::stats($idAdmin, $idAluno);
		$now = date('Y-m-d H:i:s');
		$unlockedCount = (int)($stats['conquistas_unlocked'] ?? 0);

		foreach ($defs as $def) {
			$metaTipo = (string)$def->meta_tipo;
			$meta = max(1, (int)$def->meta_valor);

			if ($metaTipo === 'conquistas_unlocked') {
				$stats['conquistas_unlocked'] = $unlockedCount;
			}

			$progresso = self::progressoPara($metaTipo, $meta, $stats);
			$unlockedAuto = ($metaTipo !== 'manual') && ($progresso >= $meta);

			$row = LmsConquistaAluno::getByAlunoSlug($idAluno, (string)$def->slug);
			if (!$row) {
				$row = new LmsConquistaAluno();
				$row->id_admin = $idAdmin;
				$row->id_aluno = $idAluno;
				$row->slug = (string)$def->slug;
				$row->origem = 'auto';
			}

			$manual = (($row->origem ?? 'auto') === 'manual') && !empty($row->unlocked_at);
			$foiNova = false;
			$jaTinha = !empty($row->unlocked_at);

			$row->id_admin = $idAdmin;
			$row->progresso = min($progresso, $meta);
			$row->meta = $meta;

			if ($manual) {
				$row->origem = 'manual';
				if ((int)$row->progresso < $meta) {
					$row->progresso = $meta;
				}
			} elseif ($unlockedAuto) {
				if (empty($row->unlocked_at)) {
					$row->unlocked_at = $now;
					$foiNova = true;
				}
				$row->origem = 'auto';
			} else {
				$row->unlocked_at = null;
				$row->origem = 'auto';
			}

			$row->salvar();

			if (!$jaTinha && !empty($row->unlocked_at)) {
				$unlockedCount++;
			}

			if ($foiNova) {
				LmsNotificacaoHelper::criar(
					$idAdmin,
					$idAluno,
					'system',
					'Nova conquista: '.(trim((string)($def->subtitulo ?? '')) ?: (string)$def->titulo),
					(string)$def->titulo,
					'/achievements',
					'ach:'.(string)$def->slug
				);
			}
		}
	}

	/** Lista completa para GET /achievements. */
	public static function listForApi(int $idAdmin, int $idAluno): array {
		if (!self::tabelasExistem()) {
			return [];
		}
		self::recalcular($idAdmin, $idAluno);
		$defs = LmsConquistaDef::listAtivasParaEscola($idAdmin);
		$map = LmsConquistaAluno::mapByAluno($idAluno);
		$out = [];
		foreach ($defs as $def) {
			$out[] = self::formatItem($def, $map[(string)$def->slug] ?? null);
		}
		return $out;
	}

	/**
	 * Top 6 para dashboard: desbloqueadas recentes + quase prontas + resto.
	 * @return array<int,array<string,mixed>>
	 */
	public static function listForDashboard(int $idAdmin, int $idAluno, int $limit = 6): array {
		$all = self::listForApi($idAdmin, $idAluno);
		if (!$all) {
			return [];
		}

		$unlocked = [];
		$almost = [];
		$rest = [];
		foreach ($all as $item) {
			if (!empty($item['unlockedAt'])) {
				$unlocked[] = $item;
				continue;
			}
			$goal = max(1, (int)($item['goal'] ?? 1));
			$prog = (int)($item['progress'] ?? 0);
			$ratio = $prog / $goal;
			if ($ratio >= 0.5) {
				$almost[] = $item;
			} else {
				$rest[] = $item;
			}
		}

		usort($unlocked, static function ($a, $b) {
			return strcmp((string)($b['unlockedAt'] ?? ''), (string)($a['unlockedAt'] ?? ''));
		});
		usort($almost, static function ($a, $b) {
			$ra = ((int)($a['progress'] ?? 0)) / max(1, (int)($a['goal'] ?? 1));
			$rb = ((int)($b['progress'] ?? 0)) / max(1, (int)($b['goal'] ?? 1));
			return $rb <=> $ra;
		});

		$merged = array_merge($unlocked, $almost, $rest);
		return array_slice($merged, 0, $limit);
	}

	/** @return array<string,mixed> */
	private static function formatItem(LmsConquistaDef $def, ?LmsConquistaAluno $row): array {
		$meta = max(1, (int)($row->meta ?? $def->meta_valor));
		$progresso = (int)($row->progresso ?? 0);
		$badge = trim((string)($def->badge_url ?? ''));
		$unlocked = !empty($row->unlocked_at);
		$raridade = LmsConquistaDef::normalizarRaridade($def->raridade ?? 'bronze');
		$secretoLocked = ($raridade === 'secreto' && !$unlocked);

		if ($secretoLocked) {
			return [
				'id' => (string)$def->slug,
				'subtitle' => 'Missão secreta',
				'title' => '???',
				'description' => 'Continue explorando o portal para descobrir esta conquista.',
				'howTo' => '',
				'icon' => 'Lock',
				'rarity' => 'secreto',
				'badgeUrl' => null,
				'secret' => true,
			];
		}

		$item = [
			'id' => (string)$def->slug,
			'subtitle' => (string)($def->subtitulo ?? ''),
			'title' => (string)$def->titulo,
			'description' => (string)$def->descricao,
			'howTo' => (string)($def->como ?? ''),
			'icon' => (string)($def->icone ?: 'Trophy'),
			'rarity' => $raridade,
			'badgeUrl' => $badge !== '' ? BrandingHelper::urlBadgeConquista($badge) : null,
		];
		if ($raridade === 'secreto') {
			$item['secret'] = true;
		}
		if ($unlocked) {
			$item['unlockedAt'] = date('c', strtotime((string)$row->unlocked_at));
			$item['progress'] = $meta;
			$item['goal'] = $meta;
		} else {
			$item['progress'] = $progresso;
			$item['goal'] = $meta;
		}
		return $item;
	}

	/** @return array<string,mixed> */
	private static function stats(int $idAdmin, int $idAluno): array {
		$aulas = 0;
		$estudo = LmsEstudoHelper::minutosAluno($idAluno, $idAdmin);
		try {
			$sql = 'SELECT COUNT(*) AS total
				FROM lms_progresso_aula p
				WHERE p.id_aluno = '.(int)$idAluno.'
				AND p.id_admin = '.(int)$idAdmin.'
				AND p.concluida_em IS NOT NULL';
			$row = (new Database())->execute($sql)->fetch(PDO::FETCH_ASSOC);
			$aulas = (int)($row['total'] ?? 0);
		} catch (\Throwable $e) {
			$aulas = 0;
		}

		$xp = LmsXpHelper::totalAluno($idAluno, $idAdmin);
		$nivel = LmsXpHelper::levelFromXp($xp);
		$streak = LmsStreakHelper::streakDays($idAluno, $idAdmin);
		$certs = LmsCertificado::countByAluno($idAluno, $idAdmin);

		$notaMax = 0.0;
		$atividadesOk = 0;
		$nota100Count = 0;
		try {
			$row = (new Database('lms_atividade_tentativas'))->select(
				'id_aluno = '.(int)$idAluno.' AND nota IS NOT NULL',
				null,
				null,
				'MAX(nota) AS m,
				 SUM(CASE WHEN nota >= 70 THEN 1 ELSE 0 END) AS ok,
				 COUNT(DISTINCT CASE WHEN nota >= 100 THEN id_atividade END) AS n100'
			)->fetch(PDO::FETCH_ASSOC);
			$notaMax = (float)($row['m'] ?? 0);
			$atividadesOk = (int)($row['ok'] ?? 0);
			$nota100Count = (int)($row['n100'] ?? 0);
		} catch (\Throwable $e) {
			$notaMax = 0.0;
			$atividadesOk = 0;
			$nota100Count = 0;
		}

		$roleplaysOk = 0;
		try {
			if (class_exists(LmsRoleplaySessao::class)) {
				$row = (new Database('lms_roleplay_sessoes'))->select(
					'id_aluno = '.(int)$idAluno.' AND score IS NOT NULL',
					null,
					null,
					'MAX(score) AS m, COUNT(*) AS total'
				)->fetch(PDO::FETCH_ASSOC);
				$notaMax = max($notaMax, (float)($row['m'] ?? 0));
				$roleplaysOk = (int)($row['total'] ?? 0);
			}
		} catch (\Throwable $e) {
			// ignore
		}

		$cursosAvaliados = 0;
		try {
			$row = (new Database('lms_curso_avaliacoes'))->select(
				'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin,
				null,
				null,
				'COUNT(*) AS total'
			)->fetch(PDO::FETCH_ASSOC);
			$cursosAvaliados = (int)($row['total'] ?? 0);
		} catch (\Throwable $e) {
			$cursosAvaliados = 0;
		}

		$avatarOk = 0;
		try {
			if (User::temColunaFoto()) {
				$u = User::getUserById($idAluno);
				$foto = $u ? trim((string)($u->foto ?? '')) : '';
				$avatarOk = ($foto !== '') ? 1 : 0;
			}
		} catch (\Throwable $e) {
			$avatarOk = 0;
		}

		$conquistasUnlocked = 0;
		try {
			$row = (new Database('lms_conquistas_aluno'))->select(
				'id_aluno = '.(int)$idAluno.' AND unlocked_at IS NOT NULL',
				null,
				null,
				'COUNT(*) AS total'
			)->fetch(PDO::FETCH_ASSOC);
			$conquistasUnlocked = (int)($row['total'] ?? 0);
		} catch (\Throwable $e) {
			$conquistasUnlocked = 0;
		}

		$cursoAtividades100 = self::countCursosAtividadesPerfeitas($idAdmin, $idAluno);

		$rank = [
			'rank_escola_1' => 0,
			'rank_escola_2' => 0,
			'rank_escola_3' => 0,
			'rank_global_1' => 0,
			'rank_global_2' => 0,
			'rank_global_3' => 0,
		];
		if (class_exists(LmsRankingDiario::class) && LmsRankingDiario::tabelaExiste()) {
			$rank['rank_escola_1'] = LmsRankingDiario::diasConsecutivosNaPosicao($idAluno, 'escola', 1, $idAdmin);
			$rank['rank_escola_2'] = LmsRankingDiario::diasConsecutivosNaPosicao($idAluno, 'escola', 2, $idAdmin);
			$rank['rank_escola_3'] = LmsRankingDiario::diasConsecutivosNaPosicao($idAluno, 'escola', 3, $idAdmin);
			$rank['rank_global_1'] = LmsRankingDiario::diasConsecutivosNaPosicao($idAluno, 'global', 1, 0);
			$rank['rank_global_2'] = LmsRankingDiario::diasConsecutivosNaPosicao($idAluno, 'global', 2, 0);
			$rank['rank_global_3'] = LmsRankingDiario::diasConsecutivosNaPosicao($idAluno, 'global', 3, 0);
		}

		return array_merge([
			'aulas' => $aulas,
			'xp' => $xp,
			'nivel' => $nivel,
			'nota_max' => $notaMax,
			'streak' => $streak,
			'estudo_min' => $estudo,
			'certs' => $certs,
			'atividades_ok' => $atividadesOk,
			'roleplays_ok' => $roleplaysOk,
			'cursos_avaliados' => $cursosAvaliados,
			'avatar_ok' => $avatarOk,
			'conquistas_unlocked' => $conquistasUnlocked,
			'curso_atividades_100' => $cursoAtividades100,
			'nota_100_count' => $nota100Count,
			'manual' => 0,
		], $rank);
	}

	/** Cursos em que o aluno tirou 100% em todas as atividades do curso. */
	private static function countCursosAtividadesPerfeitas(int $idAdmin, int $idAluno): int {
		try {
			$sql = 'SELECT a.id_curso,
				COUNT(DISTINCT a.id) AS total_ativ,
				COUNT(DISTINCT CASE WHEN t.nota >= 100 THEN a.id END) AS ok_ativ
				FROM lms_atividades a
				INNER JOIN lms_cursos c ON c.id = a.id_curso AND c.id_admin = '.(int)$idAdmin.'
				LEFT JOIN lms_atividade_tentativas t
					ON t.id_atividade = a.id AND t.id_aluno = '.(int)$idAluno.'
				GROUP BY a.id_curso
				HAVING total_ativ > 0 AND ok_ativ = total_ativ';
			$stmt = (new Database())->execute($sql);
			$n = 0;
			while ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
				$n++;
			}
			return $n;
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/**
	 * Libera conquista manualmente (escola). Idempotente.
	 * @return array{ok:bool,message:string,item?:array}
	 */
	public static function concederManual(int $idAdmin, int $idAluno, string $slug): array {
		if (!self::tabelasExistem()) {
			return ['ok' => false, 'message' => 'Tabelas de conquistas ausentes.'];
		}
		$slug = trim($slug);
		$def = LmsConquistaDef::getBySlug($slug);
		if (!$def || empty($def->ativo)) {
			return ['ok' => false, 'message' => 'Conquista não encontrada.'];
		}
		$visiveis = LmsConquistaDef::listAtivasParaEscola($idAdmin);
		$okVisivel = false;
		foreach ($visiveis as $v) {
			if ((string)$v->slug === $slug) {
				$okVisivel = true;
				break;
			}
		}
		if (!$okVisivel) {
			return ['ok' => false, 'message' => 'Conquista desativada para esta escola.'];
		}

		$meta = max(1, (int)$def->meta_valor);
		$row = LmsConquistaAluno::getByAlunoSlug($idAluno, $slug);
		if (!$row) {
			$row = new LmsConquistaAluno();
			$row->id_admin = $idAdmin;
			$row->id_aluno = $idAluno;
			$row->slug = $slug;
		}
		$jaTinha = !empty($row->unlocked_at);
		$row->id_admin = $idAdmin;
		$row->progresso = $meta;
		$row->meta = $meta;
		$row->unlocked_at = $row->unlocked_at ?: date('Y-m-d H:i:s');
		$row->origem = 'manual';
		$row->salvar();

		if (!$jaTinha) {
			LmsNotificacaoHelper::criar(
				$idAdmin,
				$idAluno,
				'system',
				'Nova conquista: '.(trim((string)($def->subtitulo ?? '')) ?: (string)$def->titulo),
				(string)$def->titulo,
				'/achievements',
				'ach:'.$slug
			);
		}

		return [
			'ok' => true,
			'message' => $jaTinha ? 'Conquista já estava liberada.' : 'Conquista liberada.',
			'item' => self::formatItem($def, $row),
		];
	}

	/**
	 * Ativa/desativa conquista para a escola (lms_escola_conquistas).
	 * Se a escola ainda não tem overrides, ao desativar uma cria mapa com todas ativas exceto a desligada.
	 */
	public static function setEscolaAtivo(int $idAdmin, string $slug, bool $ativo): array {
		if (!self::tabelasExistem() || $idAdmin <= 0) {
			return ['ok' => false, 'message' => 'Dados inválidos.'];
		}
		$slug = trim($slug);
		$def = LmsConquistaDef::getBySlug($slug);
		if (!$def) {
			return ['ok' => false, 'message' => 'Conquista não encontrada.'];
		}

		$db = new Database('lms_escola_conquistas');
		$tem = false;
		try {
			$cnt = $db->select('id_admin = '.(int)$idAdmin, null, null, 'COUNT(*) AS c')->fetch(PDO::FETCH_ASSOC);
			$tem = ((int)($cnt['c'] ?? 0)) > 0;
		} catch (\Throwable $e) {
			return ['ok' => false, 'message' => 'Tabela lms_escola_conquistas ausente.'];
		}

		if (!$tem) {
			// Primeira customização: copia todas ativas e aplica o toggle
			foreach (LmsConquistaDef::listAtivas() as $d) {
				$s = (string)$d->slug;
				$on = ($s === $slug) ? ($ativo ? 1 : 0) : 1;
				$db->insert([
					'id_admin' => $idAdmin,
					'slug' => $s,
					'ativo' => $on,
				]);
			}
			return ['ok' => true, 'message' => 'Preferências da escola salvas.'];
		}

		$exists = $db->select(
			'id_admin = '.(int)$idAdmin.' AND slug = "'.addslashes($slug).'"',
			null,
			'1'
		)->fetch(PDO::FETCH_ASSOC);
		if ($exists) {
			$db->update(
				'id_admin = '.(int)$idAdmin.' AND slug = "'.addslashes($slug).'"',
				['ativo' => $ativo ? 1 : 0]
			);
		} else {
			$db->insert([
				'id_admin' => $idAdmin,
				'slug' => $slug,
				'ativo' => $ativo ? 1 : 0,
			]);
		}
		return ['ok' => true, 'message' => 'Atualizado.'];
	}

	/** @return array<int,array<string,mixed>> */
	public static function listParaEscolaAdmin(int $idAdmin): array {
		if (!self::tabelasExistem()) {
			return [];
		}
		$todas = LmsConquistaDef::listAtivas();
		$visiveis = [];
		foreach (LmsConquistaDef::listAtivasParaEscola($idAdmin) as $d) {
			$visiveis[(string)$d->slug] = true;
		}
		$temOverride = false;
		try {
			$cnt = (new Database('lms_escola_conquistas'))->select(
				'id_admin = '.(int)$idAdmin,
				null,
				null,
				'COUNT(*) AS c'
			)->fetch(PDO::FETCH_ASSOC);
			$temOverride = ((int)($cnt['c'] ?? 0)) > 0;
		} catch (\Throwable $e) {
			$temOverride = false;
		}

		$out = [];
		foreach ($todas as $d) {
			$slug = (string)$d->slug;
			$ativoEscola = $temOverride ? !empty($visiveis[$slug]) : true;
			$out[] = [
				'slug' => $slug,
				'titulo' => (string)$d->titulo,
				'subtitulo' => (string)($d->subtitulo ?? ''),
				'raridade' => LmsConquistaDef::normalizarRaridade($d->raridade ?? 'bronze'),
				'meta_tipo' => (string)$d->meta_tipo,
				'meta_valor' => (int)$d->meta_valor,
				'ativo_escola' => $ativoEscola ? 1 : 0,
				'badge_url' => trim((string)($d->badge_url ?? '')) !== ''
					? BrandingHelper::urlBadgeConquista((string)$d->badge_url)
					: null,
			];
		}
		return $out;
	}

	private static function progressoPara(string $tipo, int $meta, array $stats): int {
		switch ($tipo) {
			case 'aulas_concluidas':
				return (int)$stats['aulas'];
			case 'xp_total':
				return (int)$stats['xp'];
			case 'nivel':
				return (int)$stats['nivel'];
			case 'nota_min':
				return ((float)$stats['nota_max'] >= $meta) ? $meta : 0;
			case 'nota_100':
				return ((float)$stats['nota_max'] >= 100) ? $meta : 0;
			case 'streak':
				return (int)$stats['streak'];
			case 'estudo_min':
				return (int)$stats['estudo_min'];
			case 'certificados':
				return (int)$stats['certs'];
			case 'atividades_ok':
				return (int)$stats['atividades_ok'];
			case 'roleplays_ok':
				return (int)$stats['roleplays_ok'];
			case 'cursos_avaliados':
				return (int)$stats['cursos_avaliados'];
			case 'avatar_ok':
				return (int)($stats['avatar_ok'] ?? 0);
			case 'conquistas_unlocked':
				return (int)($stats['conquistas_unlocked'] ?? 0);
			case 'curso_atividades_100':
				return (int)($stats['curso_atividades_100'] ?? 0);
			case 'nota_100_count':
				return (int)($stats['nota_100_count'] ?? 0);
			case 'rank_escola_1':
			case 'rank_escola_2':
			case 'rank_escola_3':
			case 'rank_global_1':
			case 'rank_global_2':
			case 'rank_global_3':
				return (int)($stats[$tipo] ?? 0);
			case 'manual':
				return 0;
			default:
				return 0;
		}
	}
}
