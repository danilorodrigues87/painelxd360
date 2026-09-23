<?php

namespace App\Controller\Api\Student;

use App\Common\Helpers\LmsAiService;
use App\Common\Helpers\LmsUnidadeAvaliacaoHelper;
use App\Common\Helpers\StudentApiMapper;
use App\Common\Helpers\StudentEntitlement;
use App\Model\Entity\LmsAtividade;
use App\Model\Entity\LmsAtividadeTentativa;
use App\Model\Entity\LmsAula;
use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsProgressoAula;
use App\Model\Entity\LmsQuestao;
use App\Model\Entity\User;

class Assessments {

	private static function ok($data, int $code = 200): array {
		return ['code' => $code, 'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
	}

	private static function err(string $msg, int $code = 400): array {
		return self::ok(['message' => $msg], $code);
	}

	private static function atividadesDoAluno(User $u): array {
		$idAdmin = (int)$u->id_admin;
		$idAluno = (int)$u->id;
		$out = [];
		$seen = [];

		foreach (StudentEntitlement::idsTrilhasMatriculadas($idAluno, $idAdmin) as $idTrilha) {
			$curso = LmsCurso::getByTrilha($idTrilha, $idAdmin);
			if (!$curso || (int)$curso->publicado !== 1) {
				continue;
			}
			foreach (LmsAtividade::listByCurso((int)$curso->id, $idAdmin) as $at) {
				$seen[(int)$at->id] = true;
				$out[] = $at;
			}
		}

		foreach (StudentEntitlement::idsCursosEad($idAluno, $idAdmin) as $idCurso) {
			$curso = LmsCurso::getById((int)$idCurso);
			if (!$curso || !StudentEntitlement::podeAcessarCurso($curso, $idAluno, $idAdmin)) {
				continue;
			}
			$idOwner = StudentEntitlement::idAdminConteudo($curso);
			foreach (LmsAtividade::listByCurso((int)$curso->id, $idOwner) as $at) {
				if (!empty($seen[(int)$at->id])) {
					continue;
				}
				$seen[(int)$at->id] = true;
				$out[] = $at;
			}
		}
		return $out;
	}

	/** @return array{0: ?LmsAtividade, 1: int, 2: ?array} */
	private static function loadAtividade(User $u, int $id): array {
		$resolved = StudentEntitlement::resolveAtividadeAluno($id, (int)$u->id, (int)$u->id_admin);
		if (!$resolved) {
			return [null, 0, self::err('Avaliação não encontrada.', 404)];
		}
		return [$resolved['atividade'], (int)$resolved['idOwner'], null];
	}

	private static function isTrueish(string $v): bool {
		return in_array(strtolower(trim($v)), ['1', 'true', 'v', 'verdadeiro', 'sim', 's'], true);
	}

	private static function gradeObjective(LmsQuestao $q, string $given): array {
		$given = trim($given);
		$correct = trim((string)$q->resposta_correta);
		$ok = false;
		if ($q->tipo === 'boolean') {
			if ($given !== '') {
				$ok = self::isTrueish($correct) === self::isTrueish($given);
			}
		} else {
			$ok = $given !== '' && strcasecmp($correct, $given) === 0;
		}
		return [
			'answer' => $given,
			'correct' => $ok,
			'score' => $ok ? 100 : 0,
			'feedback' => $ok ? 'Correto.' : 'Incorreto.',
			'answeredAt' => date('c'),
		];
	}

	private static function lessonContext(LmsAtividade $at, int $idAdmin): string {
		if (empty($at->id_aula)) {
			return (string)($at->titulo ?? '');
		}
		$aula = LmsAula::getByIdAdmin((int)$at->id_aula, $idAdmin);
		if (!$aula) {
			return (string)($at->titulo ?? '');
		}
		$parts = [
			'Título: '.(string)$aula->titulo,
			'Descrição: '.strip_tags((string)($aula->descricao ?? '')),
		];
		return mb_substr(implode("\n", $parts), 0, 2000);
	}

	private static function cicloDaAtividade(LmsAtividade $at, int $idAluno, int $idAdmin): int {
		$idAula = (int)($at->id_aula ?? 0);
		if ($idAula <= 0) {
			return 1;
		}
		$prog = LmsUnidadeAvaliacaoHelper::getOrCreateProgresso($idAluno, $idAula, $idAdmin);
		return LmsUnidadeAvaliacaoHelper::cicloAtual($prog);
	}

	private static function mapAttempt(?LmsAtividadeTentativa $tent, LmsAtividade $at, int $idAluno, int $ciclo): array {
		$max = max(1, min(10, (int)($at->tentativas_max ?: LmsUnidadeAvaliacaoHelper::TENTATIVAS_POR_CICLO)));
		$used = LmsAtividadeTentativa::countCompletedCiclo($idAluno, (int)$at->id, $ciclo);
		$answersOut = [];
		if ($tent) {
			foreach ($tent->decodeRespostas() as $qid => $entry) {
				if (is_array($entry)) {
					$answersOut[(string)$qid] = [
						'answer' => (string)($entry['answer'] ?? ''),
						'correct' => array_key_exists('correct', $entry) ? (bool)$entry['correct'] : null,
						'score' => isset($entry['score']) ? (int)$entry['score'] : null,
						'feedback' => $entry['feedback'] ?? null,
						'locked' => true,
					];
				} else {
					$answersOut[(string)$qid] = [
						'answer' => (string)$entry,
						'correct' => null,
						'score' => null,
						'feedback' => null,
						'locked' => true,
					];
				}
			}
		}
		$progMsg = null;
		$idAula = (int)($at->id_aula ?? 0);
		if ($idAula > 0) {
			$prog = LmsProgressoAula::getAlunoAula($idAluno, $idAula);
			if ($prog && (int)($prog->precisa_revisar ?? 0) === 1) {
				$progMsg = 'Média da aula abaixo de 70%. Assista a aula novamente para liberar +'.$max.' tentativas.';
			}
		}
		return [
			'id' => $tent ? (string)$tent->id : null,
			'status' => $tent ? (string)($tent->status ?: 'completed') : null,
			'score' => $tent && $tent->nota !== null ? (float)$tent->nota : null,
			'feedback' => $tent ? (string)($tent->feedback ?? '') : null,
			'answers' => $answersOut,
			'attemptsUsed' => $used,
			'attemptsMax' => $max,
			'cycle' => $ciclo,
			'canStart' => $used < $max && (!$tent || (string)$tent->status === 'completed'),
			'canAnswer' => $tent && (string)$tent->status === 'in_progress',
			'rewatchHint' => $progMsg,
		];
	}

	private static function payload(LmsAtividade $at, User $u, int $idOwner): array {
		$idEscola = (int)$u->id_admin;
		$idAluno = (int)$u->id;
		$data = StudentApiMapper::assessment($at, $idOwner, false);
		$ciclo = self::cicloDaAtividade($at, $idAluno, $idEscola);
		$inProg = LmsAtividadeTentativa::getInProgress($idAluno, (int)$at->id, $ciclo);
		$last = null;
		if (!$inProg) {
			$all = LmsAtividadeTentativa::listByAlunoAtividadeCiclo($idAluno, (int)$at->id, $ciclo);
			$last = $all[0] ?? null;
		}
		$data['attempt'] = self::mapAttempt($inProg ?: $last, $at, $idAluno, $ciclo);
		$data['attempts'] = $data['attempt']['attemptsMax'];
		$data['bestScore'] = LmsUnidadeAvaliacaoHelper::melhorNotaAtividade($idAluno, (int)$at->id, $ciclo);
		$idAula = (int)($at->id_aula ?? 0);
		if ($idAula > 0) {
			$unit = LmsUnidadeAvaliacaoHelper::avaliarUnidade($idAluno, $idAula, $idEscola, $idOwner);
			$data['unitScore'] = $unit['average'];
			$data['unitPassed'] = $unit['passed'];
			$data['unitDetails'] = $unit['details'];
			$data['needsRewatch'] = !empty($unit['allDone']) && !$unit['passed'];
		}
		return $data;
	}

	public static function list($request) {
		$u = $request->user;
		$list = [];
		foreach (self::atividadesDoAluno($u) as $at) {
			$resolved = StudentEntitlement::resolveAtividadeAluno((int)$at->id, (int)$u->id, (int)$u->id_admin);
			$idOwner = $resolved ? (int)$resolved['idOwner'] : (int)$u->id_admin;
			$list[] = self::payload($at, $u, $idOwner);
		}
		return self::ok($list);
	}

	public static function get($request, $id) {
		$u = $request->user;
		[$at, $idOwner, $err] = self::loadAtividade($u, (int)$id);
		if ($err) {
			return $err;
		}
		return self::ok(self::payload($at, $u, $idOwner));
	}

	/** Inicia tentativa in_progress (se ainda houver cota no ciclo). */
	public static function start($request, $id) {
		$u = $request->user;
		[$at, $idOwner, $err] = self::loadAtividade($u, (int)$id);
		if ($err) {
			return $err;
		}
		$ciclo = self::cicloDaAtividade($at, (int)$u->id, (int)$u->id_admin);
		$idAula = (int)($at->id_aula ?? 0);
		if ($idAula > 0) {
			$prog = LmsUnidadeAvaliacaoHelper::getOrCreateProgresso((int)$u->id, $idAula, (int)$u->id_admin);
			if ((int)($prog->precisa_revisar ?? 0) === 1 || empty($prog->concluida_em)) {
				return self::err('Assista (ou reassista) a aula para liberar as atividades.', 403);
			}
		}
		$existing = LmsAtividadeTentativa::getInProgress((int)$u->id, (int)$at->id, $ciclo);
		if ($existing) {
			return self::ok(self::payload($at, $u, $idOwner));
		}
		$max = max(1, min(10, (int)($at->tentativas_max ?: LmsUnidadeAvaliacaoHelper::TENTATIVAS_POR_CICLO)));
		$used = LmsAtividadeTentativa::countCompletedCiclo((int)$u->id, (int)$at->id, $ciclo);
		if ($used >= $max) {
			if ($idAula > 0) {
				LmsUnidadeAvaliacaoHelper::sincronizarUnidade((int)$u->id, $idAula, (int)$u->id_admin, $idOwner);
			}
			return self::err('Limite de 3 tentativas esgotado neste ciclo. Assista a aula novamente para liberar +3 tentativas.', 403);
		}
		$tent = new LmsAtividadeTentativa();
		$tent->id_aluno = (int)$u->id;
		$tent->id_atividade = (int)$at->id;
		$tent->id_admin = (int)$u->id_admin;
		$tent->respostas = [];
		$tent->nota = null;
		$tent->feedback = null;
		$tent->strengths = [];
		$tent->improvements = [];
		$tent->competencies = [];
		$tent->status = 'in_progress';
		$tent->ciclo = $ciclo;
		$tent->salvar();
		return self::ok(self::payload($at, $u, $idOwner));
	}

	/** Responde 1 questão (trava imediata). Essay é corrigida por IA na hora. */
	public static function answer($request, $id) {
		$u = $request->user;
		[$at, $idOwner, $err] = self::loadAtividade($u, (int)$id);
		if ($err) {
			return $err;
		}
		$post = $request->getPostVars() ?: [];
		$qid = (string)($post['questionId'] ?? '');
		$answer = trim((string)($post['answer'] ?? ''));
		if ($qid === '' || $answer === '') {
			return self::err('Informe questionId e answer.');
		}

		$ciclo = self::cicloDaAtividade($at, (int)$u->id, (int)$u->id_admin);
		$tent = LmsAtividadeTentativa::getInProgress((int)$u->id, (int)$at->id, $ciclo);
		if (!$tent) {
			$max = max(1, min(10, (int)($at->tentativas_max ?: LmsUnidadeAvaliacaoHelper::TENTATIVAS_POR_CICLO)));
			$used = LmsAtividadeTentativa::countCompletedCiclo((int)$u->id, (int)$at->id, $ciclo);
			if ($used >= $max) {
				return self::err('Limite de tentativas esgotado neste ciclo. Assista a aula novamente.', 403);
			}
			$tent = new LmsAtividadeTentativa();
			$tent->id_aluno = (int)$u->id;
			$tent->id_atividade = (int)$at->id;
			$tent->id_admin = (int)$u->id_admin;
			$tent->respostas = [];
			$tent->status = 'in_progress';
			$tent->ciclo = $ciclo;
			$tent->salvar();
			$tent = LmsAtividadeTentativa::getInProgress((int)$u->id, (int)$at->id, $ciclo);
		}

		$questoes = LmsQuestao::listByAtividade((int)$at->id, $idOwner);
		$q = null;
		foreach ($questoes as $row) {
			if ((string)$row->id === $qid) {
				$q = $row;
				break;
			}
		}
		if (!$q) {
			return self::err('Questão não encontrada.', 404);
		}

		$respostas = $tent->decodeRespostas();
		if (isset($respostas[$qid]) || isset($respostas[(int)$qid])) {
			return self::err('Esta questão já foi respondida e não pode ser alterada.', 409);
		}

		if ($q->tipo === 'essay') {
			$ctx = self::lessonContext($at, $idOwner);
			$grade = LmsAiService::gradeEssay(
				$idOwner,
				(string)$q->enunciado,
				$answer,
				$ctx,
				(string)($q->resposta_correta ?? '')
			);
			$entry = [
				'answer' => $answer,
				'correct' => !empty($grade['correct']),
				'score' => (int)$grade['score'],
				'feedback' => (string)$grade['feedback'],
				'answeredAt' => date('c'),
			];
		} else {
			$entry = self::gradeObjective($q, $answer);
		}

		$respostas[$qid] = $entry;
		$tent->respostas = $respostas;
		$tent->status = 'in_progress';
		$tent->ciclo = $ciclo;
		$tent->salvar();

		$answered = count($respostas);
		$total = count($questoes);
		return self::ok([
			'questionId' => $qid,
			'locked' => true,
			'correct' => $entry['correct'],
			'score' => $entry['score'],
			'feedback' => $entry['feedback'] ?? null,
			'answeredCount' => $answered,
			'totalQuestions' => $total,
			'allAnswered' => $answered >= $total,
			'attempt' => self::mapAttempt($tent, $at, (int)$u->id, $ciclo),
		]);
	}

	/** Fecha a tentativa: calcula nota média e credita XP. */
	public static function finalize($request, $id) {
		$u = $request->user;
		[$at, $idOwner, $err] = self::loadAtividade($u, (int)$id);
		if ($err) {
			return $err;
		}
		$ciclo = self::cicloDaAtividade($at, (int)$u->id, (int)$u->id_admin);
		$tent = LmsAtividadeTentativa::getInProgress((int)$u->id, (int)$at->id, $ciclo);
		if (!$tent) {
			return self::err('Nenhuma tentativa em andamento.');
		}
		$questoes = LmsQuestao::listByAtividade((int)$at->id, $idOwner);
		$respostas = $tent->decodeRespostas();
		if (count($questoes) > 0 && count($respostas) < count($questoes)) {
			return self::err('Responda todas as questões antes de finalizar.');
		}

		$sum = 0;
		$n = 0;
		$strengths = [];
		$improvements = [];
		foreach ($questoes as $q) {
			$qid = (string)$q->id;
			$entry = $respostas[$qid] ?? $respostas[(int)$qid] ?? null;
			if (!is_array($entry)) {
				continue;
			}
			$sc = (int)($entry['score'] ?? 0);
			$sum += $sc;
			$n++;
			if (!empty($entry['correct'])) {
				$strengths[] = mb_substr((string)$q->enunciado, 0, 60);
			} else {
				$improvements[] = mb_substr((string)$q->enunciado, 0, 60);
			}
		}
		$score = $n > 0 ? (int)round($sum / $n) : 100;
		$feedback = $score >= 70
			? 'Parabéns! Você concluiu a atividade com bom aproveitamento.'
			: 'Revise o conteúdo da aula. A média da unidade (atividades + roleplay) precisa ser ≥ 70%.';

		$tent->nota = $score;
		$tent->feedback = $feedback;
		$tent->strengths = array_slice($strengths, 0, 5);
		$tent->improvements = array_slice($improvements, 0, 5);
		$tent->competencies = [['name' => 'Conhecimento', 'score' => $score]];
		$tent->status = 'completed';
		$tent->ciclo = $ciclo;
		$tent->salvar();

		$xp = \App\Common\Helpers\LmsXpHelper::creditAssessment(
			(int)$u->id_admin,
			(int)$u->id,
			(int)$at->id,
			(float)$score,
			$score >= 70
		);

		$unit = null;
		$idAula = (int)($at->id_aula ?? 0);
		if ($idAula > 0) {
			$unit = LmsUnidadeAvaliacaoHelper::sincronizarUnidade((int)$u->id, $idAula, (int)$u->id_admin, $idOwner);
		}

		$certId = null;
		$curso = LmsCurso::getById((int)$at->id_curso);
		if ($curso) {
			$cert = \App\Common\Helpers\LmsCertificadoHelper::emitirSeCursoCompleto(
				(int)$u->id,
				(int)$u->id_admin,
				$curso
			);
			$certId = $cert ? (string)$cert->id : null;
		}

		\App\Common\Helpers\LmsNotificacaoHelper::criar(
			(int)$u->id_admin,
			(int)$u->id,
			'course',
			'Atividade finalizada',
			((string)($at->titulo ?? 'Atividade')).' — nota '.$score.'%'.($xp > 0 ? ' (+'.$xp.' XP)' : ''),
			!empty($at->id_curso) ? '/courses/'.(int)$at->id_curso : null,
			'asm:'.(int)$tent->id
		);

		\App\Common\Helpers\LmsConquistaHelper::recalcular((int)$u->id_admin, (int)$u->id);

		return self::ok([
			'id' => (string)$tent->id,
			'assessmentId' => (string)$at->id,
			'score' => $score,
			'feedback' => $feedback,
			'strengths' => $tent->strengths,
			'improvements' => $tent->improvements,
			'competencies' => [['name' => 'Conhecimento', 'score' => $score]],
			'submittedAt' => date('c'),
			'xpEarned' => $xp,
			'passed' => $score >= 70,
			'unitScore' => $unit['average'] ?? null,
			'unitPassed' => $unit['passed'] ?? null,
			'needsRewatch' => !empty($unit['precisaRevisar']),
			'unitDetails' => $unit['details'] ?? [],
			'certificateIssued' => $certId,
		]);
	}

	/**
	 * Legado: envia todas as respostas de uma vez (ainda respeita tentativas_max).
	 * Preferir start → answer → finalize no player.
	 */
	public static function submit($request, $id) {
		$u = $request->user;
		[$at, $idOwner, $err] = self::loadAtividade($u, (int)$id);
		if ($err) {
			return $err;
		}
		if (LmsAtividadeTentativa::getInProgress((int)$u->id, (int)$at->id)) {
			return self::err('Há uma tentativa em andamento. Use answer/finalize.');
		}
		$used = LmsAtividadeTentativa::countCompleted((int)$u->id, (int)$at->id);
		$max = max(1, (int)$at->tentativas_max);
		if ($used >= $max) {
			return self::err('Limite de tentativas esgotado.', 403);
		}

		$post = $request->getPostVars() ?: [];
		$answers = $post['answers'] ?? [];
		if (!is_array($answers)) {
			$answers = [];
		}

		$questoes = LmsQuestao::listByAtividade((int)$at->id, $idOwner);
		$ctx = self::lessonContext($at, $idOwner);
		$respostas = [];
		$sum = 0;
		$n = 0;
		foreach ($questoes as $q) {
			$ans = (string)($answers[(string)$q->id] ?? $answers[$q->id] ?? '');
			if ($q->tipo === 'essay') {
				$grade = LmsAiService::gradeEssay(
					$idOwner,
					(string)$q->enunciado,
					$ans,
					$ctx,
					(string)($q->resposta_correta ?? '')
				);
				$entry = [
					'answer' => $ans,
					'correct' => !empty($grade['correct']),
					'score' => (int)$grade['score'],
					'feedback' => (string)$grade['feedback'],
					'answeredAt' => date('c'),
				];
			} else {
				$entry = self::gradeObjective($q, $ans);
			}
			$respostas[(string)$q->id] = $entry;
			$sum += (int)$entry['score'];
			$n++;
		}

		$score = $n > 0 ? (int)round($sum / $n) : 100;
		$feedback = $score >= 70
			? 'Bom desempenho na atividade.'
			: 'Revise o conteúdo e tente novamente.';

		$tent = new LmsAtividadeTentativa();
		$tent->id_aluno = (int)$u->id;
		$tent->id_atividade = (int)$at->id;
		$tent->id_admin = (int)$u->id_admin;
		$tent->respostas = $respostas;
		$tent->nota = $score;
		$tent->feedback = $feedback;
		$tent->strengths = $score >= 70 ? ['Domínio dos conceitos'] : [];
		$tent->improvements = $score < 70 ? ['Revisar material da aula'] : [];
		$tent->competencies = [['name' => 'Conhecimento', 'score' => $score]];
		$tent->status = 'completed';
		$tentId = $tent->salvar();

		$xp = \App\Common\Helpers\LmsXpHelper::creditAssessment(
			(int)$u->id_admin,
			(int)$u->id,
			(int)$at->id,
			(float)$score,
			$score >= 70
		);

		\App\Common\Helpers\LmsConquistaHelper::recalcular((int)$u->id_admin, (int)$u->id);

		return self::ok([
			'id' => (string)$tentId,
			'assessmentId' => (string)$at->id,
			'score' => $score,
			'feedback' => $feedback,
			'strengths' => $tent->strengths,
			'improvements' => $tent->improvements,
			'competencies' => [['name' => 'Conhecimento', 'score' => $score]],
			'submittedAt' => date('c'),
			'xpEarned' => $xp,
			'passed' => $score >= 70,
		]);
	}
}
