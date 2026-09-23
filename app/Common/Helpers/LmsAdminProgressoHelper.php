<?php

namespace App\Common\Helpers;

use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsProgressoAula;
use App\Model\Entity\User;

/**
 * Histórico EAD e liberação manual de aula (painel escola).
 */
class LmsAdminProgressoHelper {

	/**
	 * Cursos EAD do aluno com currículo e status por item.
	 * @return array{ok:bool,aluno?:array,cursos?:array,message?:string}
	 */
	public static function historicoAluno(int $idAdmin, int $idAluno): array {
		if (!LmsHelper::tabelasExistem()) {
			return ['ok' => false, 'message' => 'Execute database/lms_ead.sql no phpMyAdmin.'];
		}
		$aluno = User::getUserById($idAluno);
		if (!$aluno || (int)$aluno->id_admin !== $idAdmin || ($aluno->nivel ?? '') !== 'Cliente') {
			return ['ok' => false, 'message' => 'Aluno não encontrado.'];
		}

		$cursos = [];
		$idsTrilha = StudentEntitlement::idsTrilhasMatriculadas($idAluno, $idAdmin);
		foreach ($idsTrilha as $idTrilha) {
			$curso = LmsCurso::getByTrilha((int)$idTrilha, $idAdmin);
			if (!$curso instanceof LmsCurso) {
				continue;
			}
			$mapped = StudentApiMapper::course($curso, $idAluno, $idAdmin, true);
			$itens = [];
			$proximaTravada = null;
			foreach ($mapped['modules'] ?? [] as $mod) {
				foreach ($mod['curriculum'] ?? [] as $it) {
					$row = [
						'kind' => $it['kind'] ?? 'lesson',
						'id' => (string)($it['id'] ?? ''),
						'title' => (string)($it['title'] ?? ''),
						'moduleTitle' => (string)($mod['title'] ?? ''),
						'completed' => !empty($it['completed']),
						'locked' => !empty($it['locked']),
						'needsRewatch' => !empty($it['needsRewatch']),
						'unitPassed' => !empty($it['unitPassed']),
						'unitScore' => $it['unitScore'] ?? null,
						'lockReason' => $it['lockReason'] ?? null,
						'lockMessage' => $it['lockMessage'] ?? null,
					];
					$itens[] = $row;
					if (
						$proximaTravada === null
						&& ($row['kind'] === 'lesson')
						&& $row['locked']
						&& !$row['completed']
						&& ($row['lockReason'] === 'sequencia' || $row['lockReason'] === null)
					) {
						// Prefer sequence lock; also pick first locked lesson if reason set later
						if ($row['lockReason'] === 'sequencia') {
							$proximaTravada = $row;
						}
					}
				}
			}
			// Fallback: first locked incomplete lesson
			if ($proximaTravada === null) {
				foreach ($itens as $row) {
					if ($row['kind'] === 'lesson' && $row['locked'] && !$row['completed']) {
						$proximaTravada = $row;
						break;
					}
				}
			}

			$concluidas = 0;
			foreach ($itens as $row) {
				if ($row['kind'] === 'lesson' && $row['completed']) {
					$concluidas++;
				}
			}

			$cursos[] = [
				'id' => (string)$curso->id,
				'title' => (string)($mapped['title'] ?? ''),
				'progressPercent' => (int)($mapped['progressPercent'] ?? 0),
				'lessonsCount' => (int)($mapped['lessonsCount'] ?? 0),
				'completedCount' => $concluidas,
				'accessWindow' => $mapped['accessWindow'] ?? null,
				'items' => $itens,
				'nextLockedLessonId' => $proximaTravada['id'] ?? null,
				'nextLockedReason' => $proximaTravada['lockReason'] ?? null,
			];
		}

		return [
			'ok' => true,
			'aluno' => [
				'id' => (int)$aluno->id,
				'nome' => (string)$aluno->nome,
				'email' => (string)$aluno->email,
			],
			'cursos' => $cursos,
		];
	}

	/**
	 * Libera a próxima aula travada por sequência (aprova a unidade anterior).
	 * Se o bloqueio for agenda/cota, adiciona a aula à cota do dia.
	 * @return array{ok:bool,message:string}
	 */
	public static function liberarProxima(int $idAdmin, int $idAluno, int $idCurso): array {
		if (!LmsHelper::tabelasExistem()) {
			return ['ok' => false, 'message' => 'Tabelas LMS ausentes.'];
		}
		$aluno = User::getUserById($idAluno);
		if (!$aluno || (int)$aluno->id_admin !== $idAdmin || ($aluno->nivel ?? '') !== 'Cliente') {
			return ['ok' => false, 'message' => 'Aluno inválido.'];
		}
		$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
		if (!$curso || !StudentEntitlement::podeAcessarCurso($curso, $idAluno, $idAdmin)) {
			return ['ok' => false, 'message' => 'Curso não encontrado ou sem matrícula.'];
		}

		$mapped = StudentApiMapper::course($curso, $idAluno, $idAdmin, true);
		$prevLessonId = null;
		$target = null;

		foreach ($mapped['modules'] ?? [] as $mod) {
			foreach ($mod['lessons'] ?? [] as $lesson) {
				$locked = !empty($lesson['locked']);
				$completed = !empty($lesson['completed']) || !empty($lesson['unitPassed']);
				$reason = $lesson['lockReason'] ?? null;
				$idAula = (int)$lesson['id'];

				if (!$locked || $completed) {
					$prevLessonId = $idAula;
					continue;
				}

				// Aula travada: agir
				$target = [
					'id' => $idAula,
					'reason' => $reason,
					'prev' => $prevLessonId,
					'title' => (string)($lesson['title'] ?? ''),
				];
				break 2;
			}
		}

		if (!$target) {
			return ['ok' => false, 'message' => 'Não há próxima aula travada neste curso.'];
		}

		$reason = $target['reason'];
		if ($reason === 'sequencia' || ($reason === null && $target['prev'])) {
			if (!$target['prev']) {
				return ['ok' => false, 'message' => 'Não há unidade anterior para liberar (aula bloqueada no editor?).'];
			}
			self::forcarUnidadeAprovada($idAdmin, $idAluno, (int)$target['prev']);
			return [
				'ok' => true,
				'message' => 'Unidade anterior aprovada. A aula "'.$target['title'].'" deve liberar na sequência.',
			];
		}

		if ($reason === 'cota_esgotada' || $reason === 'fora_horario') {
			LmsAgendaAcessoHelper::registrarAulaNaCota($idAluno, $idAdmin, (int)$target['id']);
			$extra = $reason === 'fora_horario'
				? ' Se ainda estiver fora do horário, agende uma reposição em Agenda.'
				: '';
			return [
				'ok' => true,
				'message' => 'Aula "'.$target['title'].'" liberada na cota de hoje.'.$extra,
			];
		}

		// Fallback: aprovar anterior se existir, senão cota
		if ($target['prev']) {
			self::forcarUnidadeAprovada($idAdmin, $idAluno, (int)$target['prev']);
			return [
				'ok' => true,
				'message' => 'Liberação aplicada na unidade anterior ("'.$target['title'].'").',
			];
		}

		LmsAgendaAcessoHelper::registrarAulaNaCota($idAluno, $idAdmin, (int)$target['id']);
		return [
			'ok' => true,
			'message' => 'Aula "'.$target['title'].'" adicionada à cota de hoje.',
		];
	}

	private static function forcarUnidadeAprovada(int $idAdmin, int $idAluno, int $idAula): void {
		$prog = LmsProgressoAula::getAlunoAula($idAluno, $idAula);
		if (!$prog instanceof LmsProgressoAula) {
			$prog = new LmsProgressoAula();
			$prog->id_aluno = $idAluno;
			$prog->id_aula = $idAula;
			$prog->id_admin = $idAdmin;
			$prog->ciclo = 1;
		}
		$prog->id_admin = $idAdmin;
		$prog->precisa_revisar = 0;
		$prog->unidade_aprovada = 1;
		if (empty($prog->concluida_em)) {
			$prog->concluida_em = date('Y-m-d H:i:s');
		}
		$prog->ultimo_acesso = date('Y-m-d H:i:s');
		$prog->salvar();
	}

	/**
	 * Resumo turma: aluno × curso EAD publicado com matrícula ativa.
	 * Filtros: id_curso, q (nome/e-mail), status (all|not_started|in_progress|completed), min_pct.
	 *
	 * @param array{id_curso?:int,q?:string,status?:string,min_pct?:int} $filtros
	 * @return array{ok:bool,message?:string,cursos?:array,itens?:array,totais?:array}
	 */
	public static function resumoTurma(int $idAdmin, array $filtros = []): array {
		if (!LmsHelper::tabelasExistem()) {
			return ['ok' => false, 'message' => 'Execute database/lms_ead.sql no phpMyAdmin.'];
		}

		$idAdmin = (int)$idAdmin;
		$idCursoFiltro = (int)($filtros['id_curso'] ?? 0);
		$q = trim((string)($filtros['q'] ?? ''));
		$status = (string)($filtros['status'] ?? 'all');
		$minPct = max(0, min(100, (int)($filtros['min_pct'] ?? 0)));
		if (!in_array($status, ['all', 'not_started', 'in_progress', 'completed'], true)) {
			$status = 'all';
		}

		$sql = "
			SELECT
				u.id AS id_aluno,
				u.nome AS aluno_nome,
				u.email AS aluno_email,
				c.id AS id_curso,
				c.id_trilha,
				t.nome AS curso_titulo,
				(
					SELECT COUNT(*)
					FROM lms_aulas a
					INNER JOIN lms_modulos m ON m.id = a.id_modulo AND m.id_admin = a.id_admin
					WHERE m.id_curso = c.id AND a.id_admin = c.id_admin
				) AS lessons_count,
				(
					SELECT COUNT(DISTINCT p.id_aula)
					FROM lms_progresso_aula p
					INNER JOIN lms_aulas a2 ON a2.id = p.id_aula AND a2.id_admin = p.id_admin
					INNER JOIN lms_modulos m2 ON m2.id = a2.id_modulo AND m2.id_admin = a2.id_admin
					WHERE p.id_aluno = u.id
						AND p.id_admin = c.id_admin
						AND m2.id_curso = c.id
						AND p.concluida_em IS NOT NULL
				) AS completed_count,
				(
					SELECT COUNT(*)
					FROM lms_progresso_aula pr
					INNER JOIN lms_aulas ar ON ar.id = pr.id_aula AND ar.id_admin = pr.id_admin
					INNER JOIN lms_modulos mr ON mr.id = ar.id_modulo AND mr.id_admin = ar.id_admin
					WHERE pr.id_aluno = u.id
						AND pr.id_admin = c.id_admin
						AND mr.id_curso = c.id
						AND pr.precisa_revisar = 1
				) AS rewatch_count
			FROM matriculas mat
			INNER JOIN usuarios u
				ON u.id = mat.id_aluno AND u.id_admin = mat.id_admin AND u.nivel = 'Cliente' AND u.ativo = 's'
			INNER JOIN lms_cursos c
				ON c.id_trilha = mat.id_trilha AND c.id_admin = mat.id_admin AND c.publicado = 1
			INNER JOIN trilhas t
				ON t.id = c.id_trilha AND t.id_admin = c.id_admin
			WHERE mat.id_admin = {$idAdmin}
				AND mat.status = 0
				AND (mat.fim IS NULL OR mat.fim >= CURDATE())
		";
		if ($idCursoFiltro > 0) {
			$sql .= ' AND c.id = '.(int)$idCursoFiltro;
		}
		$sql .= ' GROUP BY u.id, u.nome, u.email, c.id, c.id_trilha, t.nome ORDER BY u.nome ASC, t.nome ASC';

		try {
			$db = new \App\Model\Db\Database();
			$stmt = $db->execute($sql);
		} catch (\Throwable $e) {
			return ['ok' => false, 'message' => 'Falha ao consultar progresso da turma.'];
		}

		$itens = [];
		$totais = [
			'alunos_unicos' => 0,
			'linhas' => 0,
			'concluidos' => 0,
			'em_andamento' => 0,
			'nao_iniciados' => 0,
		];
		$alunosSet = [];

		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$lessons = (int)($row['lessons_count'] ?? 0);
			$done = (int)($row['completed_count'] ?? 0);
			if ($done > $lessons && $lessons > 0) {
				$done = $lessons;
			}
			$pct = $lessons > 0 ? (int)round(($done / $lessons) * 100) : 0;
			if ($pct >= 100) {
				$st = 'completed';
			} elseif ($pct > 0 || $done > 0) {
				$st = 'in_progress';
			} else {
				$st = 'not_started';
			}

			$nome = (string)($row['aluno_nome'] ?? '');
			$email = (string)($row['aluno_email'] ?? '');
			if ($q !== '') {
				$needle = mb_strtolower($q, 'UTF-8');
				$hay = mb_strtolower($nome.' '.$email, 'UTF-8');
				if (mb_strpos($hay, $needle) === false) {
					continue;
				}
			}
			if ($status !== 'all' && $st !== $status) {
				continue;
			}
			if ($pct < $minPct) {
				continue;
			}

			$idAluno = (int)$row['id_aluno'];
			$alunosSet[$idAluno] = true;
			$totais['linhas']++;
			if ($st === 'completed') {
				$totais['concluidos']++;
			} elseif ($st === 'in_progress') {
				$totais['em_andamento']++;
			} else {
				$totais['nao_iniciados']++;
			}

			$itens[] = [
				'id_aluno' => $idAluno,
				'aluno_nome' => $nome,
				'aluno_email' => $email,
				'id_curso' => (int)$row['id_curso'],
				'id_trilha' => (int)$row['id_trilha'],
				'curso_titulo' => (string)($row['curso_titulo'] ?? ''),
				'lessons_count' => $lessons,
				'completed_count' => $done,
				'progress_percent' => $pct,
				'status' => $st,
				'rewatch_count' => (int)($row['rewatch_count'] ?? 0),
			];
		}
		$totais['alunos_unicos'] = count($alunosSet);

		$cursosOpts = [];
		$cursosStmt = LmsCurso::get('id_admin = '.$idAdmin.' AND publicado = 1', 'id ASC');
		while ($c = $cursosStmt->fetchObject(LmsCurso::class)) {
			$cursosOpts[] = [
				'id' => (int)$c->id,
				'title' => StudentEntitlement::nomeTrilha((int)$c->id_trilha),
			];
		}

		return [
			'ok' => true,
			'cursos' => $cursosOpts,
			'itens' => $itens,
			'totais' => $totais,
		];
	}

	/**
	 * CSV UTF-8 (BOM) do resumo turma.
	 * @param array{id_curso?:int,q?:string,status?:string,min_pct?:int} $filtros
	 * @return array{ok:bool,message?:string,filename?:string,csv?:string}
	 */
	public static function exportarCsvTurma(int $idAdmin, array $filtros = []): array {
		$res = self::resumoTurma($idAdmin, $filtros);
		if (empty($res['ok'])) {
			return ['ok' => false, 'message' => $res['message'] ?? 'Erro ao exportar.'];
		}
		$lines = [];
		$lines[] = ['Aluno', 'E-mail', 'Curso', '%', 'Aulas concluídas', 'Total aulas', 'Status', 'Reassistir'];
		$statusLabel = [
			'not_started' => 'Não iniciado',
			'in_progress' => 'Em andamento',
			'completed' => 'Concluído',
		];
		foreach ($res['itens'] as $it) {
			$lines[] = [
				$it['aluno_nome'],
				$it['aluno_email'],
				$it['curso_titulo'],
				(string)$it['progress_percent'],
				(string)$it['completed_count'],
				(string)$it['lessons_count'],
				$statusLabel[$it['status']] ?? $it['status'],
				(string)$it['rewatch_count'],
			];
		}
		$fh = fopen('php://temp', 'r+');
		foreach ($lines as $row) {
			fputcsv($fh, $row, ';');
		}
		rewind($fh);
		$csv = stream_get_contents($fh);
		fclose($fh);
		$bom = "\xEF\xBB\xBF";
		return [
			'ok' => true,
			'filename' => 'progresso-ead-turma-'.date('Y-m-d').'.csv',
			'csv' => $bom.$csv,
		];
	}
}
