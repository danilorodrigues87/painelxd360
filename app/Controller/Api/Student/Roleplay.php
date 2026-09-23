<?php

namespace App\Controller\Api\Student;

use App\Common\Helpers\LmsAiService;
use App\Common\Helpers\StudentApiMapper;
use App\Common\Helpers\StudentEntitlement;
use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsRoleplayCenario;
use App\Model\Entity\LmsRoleplaySessao;
use App\Model\Entity\Trilhas;

class Roleplay {

	private static function ok($data, int $code = 200): array {
		return ['code' => $code, 'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
	}

	private static function err(string $msg, int $code = 400): array {
		return self::ok(['message' => $msg], $code);
	}

	private static function mapSessao(LmsRoleplaySessao $s, LmsRoleplayCenario $rp, string $courseTitle): array {
		$rawMsgs = $s->messages ?? [];
		if (is_string($rawMsgs)) {
			$msgs = json_decode($rawMsgs, true);
		} else {
			$msgs = $rawMsgs;
		}
		if (!is_array($msgs)) {
			$msgs = [];
		}
		$rawEval = $s->evaluation ?? null;
		if (is_string($rawEval)) {
			$eval = json_decode($rawEval, true);
		} elseif (is_array($rawEval)) {
			$eval = $rawEval;
		} else {
			$eval = null;
		}
		$limitSec = max(60, (int)$rp->estimated_minutes * 60);
		$started = $s->started_at ? strtotime($s->started_at) : time();
		$elapsed = max(0, time() - $started);
		$remaining = max(0, $limitSec - $elapsed);
		$closed = in_array((string)$s->status, ['approved', 'retry'], true) || !empty($s->ended_at);
		return [
			'id' => (string)$s->id,
			'scenarioId' => (string)$s->id_cenario,
			'scenarioTitle' => (string)$rp->titulo,
			'courseId' => (string)$rp->id_curso,
			'courseTitle' => $courseTitle,
			'moduleTitle' => '',
			'theme' => (string)($rp->tema ?? ''),
			'difficulty' => (string)$s->difficulty,
			'startedAt' => $s->started_at ? date('c', strtotime($s->started_at)) : date('c'),
			'endedAt' => $s->ended_at ? date('c', strtotime($s->ended_at)) : null,
			'durationSeconds' => (int)$s->duration_seconds,
			'timeLimitSeconds' => $limitSec,
			'timeRemainingSeconds' => $closed ? 0 : $remaining,
			'messages' => $msgs,
			'status' => (string)$s->status,
			'score' => $s->score !== null ? (float)$s->score : null,
			'evaluation' => $eval ?: null,
		];
	}

	public static function listScenarios($request) {
		$u = $request->user;
		$idAdmin = (int)$u->id_admin;
		$out = [];
		$seen = [];

		foreach (StudentEntitlement::idsTrilhasMatriculadas((int)$u->id, $idAdmin) as $idTrilha) {
			$curso = LmsCurso::getByTrilha($idTrilha, $idAdmin);
			if (!$curso || (int)$curso->publicado !== 1) {
				continue;
			}
			$title = StudentEntitlement::nomeTrilha($idTrilha);
			foreach (LmsRoleplayCenario::listByCurso((int)$curso->id, $idAdmin) as $rp) {
				$seen[(int)$rp->id] = true;
				$out[] = StudentApiMapper::roleplayScenario($rp, $title);
			}
		}

		foreach (StudentEntitlement::idsCursosEad((int)$u->id, $idAdmin) as $idCurso) {
			$curso = LmsCurso::getById((int)$idCurso);
			if (!$curso || !StudentEntitlement::podeAcessarCurso($curso, (int)$u->id, $idAdmin)) {
				continue;
			}
			$idOwner = StudentEntitlement::idAdminConteudo($curso);
			$title = StudentEntitlement::tituloCursoAluno($curso, $idAdmin);
			foreach (LmsRoleplayCenario::listByCurso((int)$curso->id, $idOwner) as $rp) {
				if (!empty($seen[(int)$rp->id])) {
					continue;
				}
				$seen[(int)$rp->id] = true;
				$out[] = StudentApiMapper::roleplayScenario($rp, $title);
			}
		}
		return self::ok($out);
	}

	/** @return array{0: ?LmsRoleplayCenario, 1: ?LmsCurso, 2: int, 3: ?array} */
	private static function loadCenario(User $u, int $id): array {
		$resolved = StudentEntitlement::resolveRoleplayAluno($id, (int)$u->id, (int)$u->id_admin);
		if (!$resolved) {
			return [null, null, 0, self::err('Cenário não encontrado.', 404)];
		}
		return [$resolved['cenario'], $resolved['curso'], (int)$resolved['idOwner'], null];
	}

	private static function tituloCurso(LmsCurso $curso, int $idAdminEscola): string {
		return StudentEntitlement::tituloCursoAluno($curso, $idAdminEscola);
	}

	public static function getScenario($request, $id) {
		$u = $request->user;
		[$rp, $curso, , $err] = self::loadCenario($u, (int)$id);
		if ($err) {
			return $err;
		}
		return self::ok(StudentApiMapper::roleplayScenario($rp, self::tituloCurso($curso, (int)$u->id_admin)));
	}

	public static function start($request) {
		$u = $request->user;
		$post = $request->getPostVars() ?: [];
		$idCenario = (int)($post['scenarioId'] ?? 0);
		$difficulty = (string)($post['difficulty'] ?? 'medium');
		[$rp, $curso, $idOwner, $err] = self::loadCenario($u, $idCenario);
		if ($err) {
			return $err;
		}
		$initial = [
			[
				'id' => 'm_'.time(),
				'role' => 'ai',
				'content' => (string)($rp->initial_message ?: 'Olá! Vamos começar a simulação.'),
				'createdAt' => date('c'),
			],
		];
		$ciclo = 1;
		if (!empty($rp->id_aula)) {
			$prog = \App\Common\Helpers\LmsUnidadeAvaliacaoHelper::getOrCreateProgresso(
				(int)$u->id,
				(int)$rp->id_aula,
				(int)$u->id_admin
			);
			if ((int)($prog->precisa_revisar ?? 0) === 1 || empty($prog->concluida_em)) {
				return self::err('Assista (ou reassista) a aula para liberar o role play.', 403);
			}
			$ciclo = \App\Common\Helpers\LmsUnidadeAvaliacaoHelper::cicloAtual($prog);
		}
		$s = new LmsRoleplaySessao();
		$s->id_cenario = $idCenario;
		$s->id_aluno = (int)$u->id;
		$s->id_admin = (int)$u->id_admin;
		$s->difficulty = in_array($difficulty, ['easy', 'medium', 'hard', 'expert'], true) ? $difficulty : 'medium';
		$s->status = 'in_progress';
		$s->messages = $initial;
		$s->ciclo = $ciclo;
		$id = $s->salvar();
		$s = LmsRoleplaySessao::getByIdAdmin($id, (int)$u->id_admin);
		$title = self::tituloCurso($curso, (int)$u->id_admin);
		return self::ok(self::mapSessao($s, $rp, $title));
	}

	public static function getSimulation($request, $id) {
		$u = $request->user;
		$s = LmsRoleplaySessao::getByIdAdmin((int)$id, (int)$u->id_admin);
		if (!$s || (int)$s->id_aluno !== (int)$u->id) {
			return self::err('Simulação não encontrada.', 404);
		}
		[$rp, $curso, , $err] = self::loadCenario($u, (int)$s->id_cenario);
		if ($err) {
			return $err;
		}
		$title = self::tituloCurso($curso, (int)$u->id_admin);
		return self::ok(self::mapSessao($s, $rp, $title));
	}

	public static function sendMessage($request, $id) {
		$u = $request->user;
		$s = LmsRoleplaySessao::getByIdAdmin((int)$id, (int)$u->id_admin);
		if (!$s || (int)$s->id_aluno !== (int)$u->id) {
			return self::err('Simulação não encontrada.', 404);
		}
		if (in_array((string)$s->status, ['approved', 'retry'], true) || !empty($s->ended_at)) {
			return self::err('Esta simulação já foi encerrada.', 403);
		}
		[$rp, $curso, , $err] = self::loadCenario($u, (int)$s->id_cenario);
		if ($err) {
			return $err;
		}
		$limitSec = max(60, (int)$rp->estimated_minutes * 60);
		$started = $s->started_at ? strtotime($s->started_at) : time();
		if (time() - $started >= $limitSec) {
			return self::err('Tempo esgotado. Finalize a simulação para ver a nota.', 403);
		}
		$post = $request->getPostVars() ?: [];
		$content = trim((string)($post['content'] ?? ''));
		if ($content === '') {
			return self::err('Mensagem vazia.');
		}
		$msgs = json_decode((string)($s->messages ?? '[]'), true) ?: [];
		$msgs[] = [
			'id' => 'm_'.time().'_u',
			'role' => 'user',
			'content' => $content,
			'createdAt' => date('c'),
		];
		$system = trim(
			($rp->base_prompt ?? '')
			."\nPersona: ".($rp->initial_personality ?? '')
			."\nPapel IA: ".($rp->ai_role ?? '')
			."\nRegras: permaneça no personagem; não saia do tema da simulação; linguagem profissional; "
			."se o aluno desviar do assunto, redirecione educadamente ao cenário."
		);
		$aiText = LmsAiService::chat((int)$u->id_admin, $msgs, $system, 'roleplay');
		$aiMsg = [
			'id' => 'm_'.time().'_a',
			'role' => 'ai',
			'content' => $aiText,
			'createdAt' => date('c'),
		];
		$msgs[] = $aiMsg;
		$s->messages = $msgs;
		$s->salvar();
		$title = self::tituloCurso($curso, (int)$u->id_admin);
		return self::ok([
			'message' => $aiMsg,
			'timeRemainingSeconds' => max(0, $limitSec - (time() - $started)),
			'simulation' => self::mapSessao($s, $rp, $title),
		]);
	}

	public static function finish($request, $id) {
		$u = $request->user;
		$s = LmsRoleplaySessao::getByIdAdmin((int)$id, (int)$u->id_admin);
		if (!$s || (int)$s->id_aluno !== (int)$u->id) {
			return self::err('Simulação não encontrada.', 404);
		}
		if (in_array((string)$s->status, ['approved', 'retry'], true) && !empty($s->ended_at)) {
			[$rp, $curso, $idOwner, $err] = self::loadCenario($u, (int)$s->id_cenario);
			if ($err) {
				return $err;
			}
			$title = self::tituloCurso($curso, (int)$u->id_admin);
			return self::ok(self::mapSessao($s, $rp, $title));
		}
		[$rp, $curso, $idOwner, $err] = self::loadCenario($u, (int)$s->id_cenario);
		if ($err) {
			return $err;
		}
		$msgs = json_decode((string)($s->messages ?? '[]'), true) ?: [];
		$scenario = StudentApiMapper::roleplayScenario($rp, '', '', false);
		$evaluation = LmsAiService::evaluateRoleplay((int)$u->id_admin, $scenario, $msgs);
		$evaluation['simulationId'] = (string)$s->id;
		$s->evaluation = $evaluation;
		$s->score = $evaluation['overallScore'];
		$s->status = !empty($evaluation['passed']) ? 'approved' : 'retry';
		$s->ended_at = date('Y-m-d H:i:s');
		$started = $s->started_at ? strtotime($s->started_at) : time();
		$s->duration_seconds = max(0, time() - $started);
		$s->salvar();
		$xp = \App\Common\Helpers\LmsXpHelper::creditRoleplay(
			(int)$u->id_admin,
			(int)$u->id,
			(int)$s->id,
			(float)($evaluation['overallScore'] ?? 0)
		);
		$unit = null;
		if (!empty($rp->id_aula)) {
			$unit = \App\Common\Helpers\LmsUnidadeAvaliacaoHelper::sincronizarUnidade(
				(int)$u->id,
				(int)$rp->id_aula,
				(int)$u->id_admin,
				$idOwner
			);
		}
		$title = self::tituloCurso($curso, (int)$u->id_admin);
		$payload = self::mapSessao($s, $rp, $title);
		$payload['xpEarned'] = $xp;
		$payload['unitScore'] = $unit['average'] ?? null;
		$payload['unitPassed'] = $unit['passed'] ?? null;
		$payload['needsRewatch'] = !empty($unit['precisaRevisar']);
		$payload['unitDetails'] = $unit['details'] ?? [];
		if ($curso) {
			$cert = \App\Common\Helpers\LmsCertificadoHelper::emitirSeCursoCompleto(
				(int)$u->id,
				(int)$u->id_admin,
				$curso
			);
			$payload['certificateIssued'] = $cert ? (string)$cert->id : null;
		}
		\App\Common\Helpers\LmsNotificacaoHelper::criar(
			(int)$u->id_admin,
			(int)$u->id,
			'course',
			'Roleplay concluído',
			((string)($rp->titulo ?? 'Roleplay')).($xp > 0 ? ' (+'.$xp.' XP)' : ''),
			!empty($rp->id_curso) ? '/courses/'.(int)$rp->id_curso : null,
			'rp:'.(int)$s->id
		);
		\App\Common\Helpers\LmsConquistaHelper::recalcular((int)$u->id_admin, (int)$u->id);
		return self::ok($payload);
	}

	public static function history($request) {
		$u = $request->user;
		$out = [];
		foreach (LmsRoleplaySessao::listByAluno((int)$u->id, (int)$u->id_admin) as $s) {
			[$rp, $curso, , $err] = self::loadCenario($u, (int)$s->id_cenario);
			if ($err || !$rp) {
				continue;
			}
			$title = self::tituloCurso($curso, (int)$u->id_admin);
			$out[] = self::mapSessao($s, $rp, $title);
		}
		return self::ok($out);
	}
}
