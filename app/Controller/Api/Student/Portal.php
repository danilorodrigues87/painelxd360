<?php

namespace App\Controller\Api\Student;

use App\Common\Helpers\LmsHelper;
use App\Common\Helpers\StudentApiMapper;
use App\Common\Helpers\StudentEntitlement;
use App\Common\Helpers\LmsXpHelper;
use App\Common\Helpers\LmsConquistaHelper;
use App\Common\Helpers\LmsNotificacaoHelper;
use App\Common\Helpers\LmsEstudoHelper;
use App\Common\Helpers\LmsPresencaHelper;
use App\Common\Helpers\LmsComentarioHelper;
use App\Common\Helpers\LmsAnotacaoHelper;
use App\Common\Helpers\BunnyStreamHelper;
use App\Model\Entity\LmsVideo;
use App\Common\Helpers\UserFotoHelper;
use App\Common\Helpers\LmsAgendaAcessoHelper;
use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsAula;
use App\Model\Entity\LmsProgressoAula;
use App\Model\Entity\User;

class Portal {

	private static function ok($data, int $code = 200): array {
		return [
			'code' => $code,
			'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	/** Resolve aula do conteúdo (tenant criador), não só do tenant do aluno. */
	private static function aulaDoCurso(LmsCurso $curso, int $lessonId): ?LmsAula {
		$idOwner = StudentEntitlement::idAdminConteudo($curso);
		$aula = LmsAula::getByIdAdmin($lessonId, $idOwner);
		if (!$aula) {
			$aula = LmsAula::getById($lessonId);
		}
		if (!$aula instanceof LmsAula) {
			return null;
		}
		return StudentEntitlement::aulaPertenceCurso($aula, $curso, $idOwner) ? $aula : null;
	}

	private static function err(string $msg, int $code = 400): array {
		return self::ok(['message' => $msg], $code);
	}

	private static function user($request): User {
		return $request->user;
	}

	public static function me($request) {
		return self::ok(StudentApiMapper::user(self::user($request)));
	}

	/** Upload de foto do aluno (multipart campo "foto"). */
	public static function updateAvatar($request) {
		$u = self::user($request);
		if (!User::temColunaFoto()) {
			return self::err('Upload de foto não disponível neste servidor.', 501);
		}
		$files = $request->getFileVars();
		$file = $files['foto'] ?? null;
		if (!is_array($file) || empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			return self::err('Envie uma imagem (JPG, PNG ou WEBP, máx. 5 MB).');
		}
		$type = strtolower((string)($file['type'] ?? ''));
		$size = (int)($file['size'] ?? 0);
		if (strpos($type, 'image/') !== 0) {
			return self::err('Arquivo deve ser uma imagem.');
		}
		if ($size <= 0 || $size > 5 * 1024 * 1024) {
			return self::err('Imagem deve ter no máximo 5 MB.');
		}
		$fotoAtual = trim((string)($u->foto ?? ''));
		$nova = UserFotoHelper::processarUpload($file, $fotoAtual !== '' ? $fotoAtual : null);
		if ($nova === null || $nova === '') {
			return self::err('Falha ao gravar a foto no servidor.');
		}
		if ($nova === $fotoAtual) {
			return self::err('Não foi possível processar a imagem.');
		}
		$u->foto = $nova;
		$u->atualizaPerfil();
		LmsConquistaHelper::recalcular((int)$u->id_admin, (int)$u->id);
		$fresh = User::getUserById((int)$u->id);
		return self::ok([
			'ok' => true,
			'message' => 'Foto atualizada.',
			'user' => StudentApiMapper::user($fresh instanceof User ? $fresh : $u),
		]);
	}

	/** Altera senha do aluno logado (senha atual + nova). */
	public static function changePassword($request) {
		$u = self::user($request);
		$post = $request->getPostVars() ?: [];
		$atual = (string)($post['currentPassword'] ?? $post['senha_atual'] ?? '');
		$nova = (string)($post['newPassword'] ?? $post['senha'] ?? $post['password'] ?? '');
		$confirma = (string)($post['confirmPassword'] ?? $post['senha_confirma'] ?? $nova);

		if ($atual === '' || $nova === '') {
			return self::err('Informe a senha atual e a nova senha.');
		}
		if (!password_verify($atual, (string)$u->senha)) {
			return self::err('Senha atual incorreta.', 400);
		}
		if (strlen($nova) < 8) {
			return self::err('A nova senha deve ter pelo menos 8 caracteres.');
		}
		if ($nova !== $confirma) {
			return self::err('A confirmação da nova senha não confere.');
		}
		if (password_verify($nova, (string)$u->senha)) {
			return self::err('A nova senha deve ser diferente da atual.');
		}

		$u->senha = password_hash($nova, PASSWORD_DEFAULT);
		$u->resetSenha();

		return self::ok([
			'ok' => true,
			'message' => 'Senha alterada com sucesso.',
		]);
	}

	public static function listCourses($request) {
		if (!LmsHelper::tabelasExistem()) {
			return self::ok([]);
		}
		$u = self::user($request);
		$idAdmin = (int)$u->id_admin;
		$idAluno = (int)$u->id;
		$out = [];
		foreach (StudentEntitlement::idsCursosEad($idAluno, $idAdmin) as $idCurso) {
			$curso = LmsCurso::getById((int)$idCurso);
			if ($curso instanceof LmsCurso && StudentEntitlement::podeAcessarCurso($curso, $idAluno, $idAdmin)) {
				$out[] = StudentApiMapper::course($curso, $idAluno, $idAdmin, true);
			}
		}
		return self::ok($out);
	}

	public static function getCourse($request, $idOrSlug) {
		$u = self::user($request);
		$bySlug = !ctype_digit((string)$idOrSlug);
		$curso = StudentEntitlement::cursoDoAluno($idOrSlug, (int)$u->id, (int)$u->id_admin, $bySlug);
		if (!$curso) {
			return self::err('Curso não encontrado ou sem acesso.', 404);
		}
		return self::ok(StudentApiMapper::course($curso, (int)$u->id, (int)$u->id_admin, true));
	}

	public static function rateCourse($request, $idOrSlug) {
		$u = self::user($request);
		$bySlug = !ctype_digit((string)$idOrSlug);
		$curso = StudentEntitlement::cursoDoAluno($idOrSlug, (int)$u->id, (int)$u->id_admin, $bySlug);
		if (!$curso) {
			return self::err('Curso não encontrado ou sem acesso.', 404);
		}
		if (!\App\Model\Entity\LmsCursoAvaliacao::tabelasExistem()) {
			return self::err('Avaliações ainda não estão disponíveis. Execute database/lms_curso_avaliacoes.sql.', 503);
		}
		$post = $request->getPostVars() ?: [];
		$nota = (int)($post['rating'] ?? $post['nota'] ?? 0);
		if ($nota < 1 || $nota > 5) {
			return self::err('Informe uma nota de 1 a 5.');
		}
		$comentario = trim((string)($post['comment'] ?? $post['comentario'] ?? ''));
		$ob = new \App\Model\Entity\LmsCursoAvaliacao();
		$ob->id_admin = (int)$u->id_admin;
		$ob->id_aluno = (int)$u->id;
		$ob->id_curso = (int)$curso->id;
		$ob->nota = $nota;
		$ob->comentario = $comentario !== '' ? $comentario : null;
		$ob->salvar();
		LmsConquistaHelper::recalcular((int)$u->id_admin, (int)$u->id);
		$media = \App\Model\Entity\LmsCursoAvaliacao::mediaCurso((int)$curso->id, (int)$u->id_admin);
		return self::ok([
			'ok' => true,
			'myRating' => $nota,
			'rating' => $media['avg'],
			'ratingCount' => $media['count'],
			'message' => 'Avaliação salva.',
		]);
	}

	public static function getLesson($request, $courseId, $lessonId) {
		$u = self::user($request);
		$curso = StudentEntitlement::cursoDoAluno($courseId, (int)$u->id, (int)$u->id_admin, !ctype_digit((string)$courseId));
		if (!$curso) {
			return self::err('Curso não encontrado ou sem acesso.', 404);
		}
		$aula = self::aulaDoCurso($curso, (int)$lessonId);
		if (!$aula) {
			return self::err('Aula não encontrada.', 404);
		}

		$idOwner = StudentEntitlement::idAdminConteudo($curso);
		$idsInc = LmsAgendaAcessoHelper::idsIncompletasDoCurso($curso, (int)$u->id, $idOwner, (int)$u->id_admin);
		$progExistente = LmsProgressoAula::getAlunoAula((int)$u->id, (int)$aula->id);
		$precisaRevisar = $progExistente && (int)($progExistente->precisa_revisar ?? 0) === 1;
		$unidadeOk = $progExistente && (int)($progExistente->unidade_aprovada ?? 0) === 1;
		$assistida = $progExistente && !empty($progExistente->concluida_em) && !$precisaRevisar;
		$semAval = count(\App\Common\Helpers\LmsUnidadeAvaliacaoHelper::itensAvaliados((int)$aula->id, $idOwner)) === 0;
		$concluidaOuRevisao = $unidadeOk || ($semAval && $assistida) || $precisaRevisar;

		$idTrilhaAgenda = LmsAgendaAcessoHelper::idTrilhaAgendaAluno((int)$u->id, (int)$u->id_admin);
		$acesso = LmsAgendaAcessoHelper::avaliarAcessoAula(
			(int)$u->id,
			(int)$u->id_admin,
			$idTrilhaAgenda,
			(int)$aula->id,
			$concluidaOuRevisao,
			$idsInc
		);

		// Fora da janela: ainda devolve o curso/aula (locked) para o portal mostrar o aviso.
		// Só registra cota / progresso de acesso quando permitido.
		if ($acesso['allowed']) {
			if (!$concluidaOuRevisao) {
				LmsAgendaAcessoHelper::registrarAulaNaCota((int)$u->id, (int)$u->id_admin, (int)$aula->id);
			}
			$prog = $progExistente;
			if (!$prog) {
				$prog = new LmsProgressoAula();
				$prog->id_aluno = (int)$u->id;
				$prog->id_aula = (int)$aula->id;
				$prog->id_admin = (int)$u->id_admin;
			}
			$prog->ultimo_acesso = date('Y-m-d H:i:s');
			$prog->salvar();
		}

		$coursePayload = StudentApiMapper::course($curso, (int)$u->id, (int)$u->id_admin, true);
		$lessonPayload = null;
		foreach ($coursePayload['modules'] as $mod) {
			foreach ($mod['lessons'] as $l) {
				if ((string)$l['id'] === (string)$aula->id) {
					$lessonPayload = $l;
					break 2;
				}
			}
		}
		if (!$lessonPayload) {
			return self::err('Aula não encontrada.', 404);
		}
		if (!$acesso['allowed']) {
			$lessonPayload['locked'] = true;
			$lessonPayload['lockReason'] = $acesso['reason'];
			$lessonPayload['lockMessage'] = $acesso['message'];
		}
		return self::ok([
			'course' => $coursePayload,
			'lesson' => $lessonPayload,
			'accessWindow' => $coursePayload['accessWindow'] ?? LmsAgendaAcessoHelper::accessWindow((int)$u->id, (int)$u->id_admin, $idTrilhaAgenda),
		]);
	}

	public static function completeLesson($request, $courseId, $lessonId) {
		$u = self::user($request);
		$curso = StudentEntitlement::cursoDoAluno($courseId, (int)$u->id, (int)$u->id_admin, !ctype_digit((string)$courseId));
		if (!$curso) {
			return self::err('Curso não encontrado ou sem acesso.', 404);
		}
		$aula = self::aulaDoCurso($curso, (int)$lessonId);
		if (!$aula) {
			return self::err('Aula não encontrada.', 404);
		}

		$idOwner = StudentEntitlement::idAdminConteudo($curso);
		$idsInc = LmsAgendaAcessoHelper::idsIncompletasDoCurso($curso, (int)$u->id, $idOwner, (int)$u->id_admin);
		$prog = LmsProgressoAula::getAlunoAula((int)$u->id, (int)$aula->id);
		$precisaRevisar = $prog && (int)($prog->precisa_revisar ?? 0) === 1;
		$unidadeOk = $prog && (int)($prog->unidade_aprovada ?? 0) === 1;
		$assistida = $prog && !empty($prog->concluida_em) && !$precisaRevisar;
		$semAval = count(\App\Common\Helpers\LmsUnidadeAvaliacaoHelper::itensAvaliados((int)$aula->id, $idOwner)) === 0;
		$concluidaOuRevisao = $unidadeOk || ($semAval && $assistida) || $precisaRevisar;

		$idTrilhaAgenda = LmsAgendaAcessoHelper::idTrilhaAgendaAluno((int)$u->id, (int)$u->id_admin);
		$acesso = LmsAgendaAcessoHelper::avaliarAcessoAula(
			(int)$u->id,
			(int)$u->id_admin,
			$idTrilhaAgenda,
			(int)$aula->id,
			$concluidaOuRevisao,
			$idsInc
		);
		if (!$acesso['allowed']) {
			return self::err($acesso['message'] ?? 'Aula bloqueada pelo horário.', 403);
		}

		if (!$prog) {
			$prog = new LmsProgressoAula();
			$prog->id_aluno = (int)$u->id;
			$prog->id_aula = (int)$aula->id;
			$prog->id_admin = (int)$u->id_admin;
			$prog->ciclo = 1;
			$prog->precisa_revisar = 0;
			$prog->unidade_aprovada = 0;
		}
		\App\Common\Helpers\LmsUnidadeAvaliacaoHelper::onCompleteLesson($prog);
		$prog->salvar();
		LmsAgendaAcessoHelper::registrarAulaNaCota((int)$u->id, (int)$u->id_admin, (int)$aula->id);

		$lesson = StudentApiMapper::lesson($aula, (int)$aula->id_modulo, $idOwner, true, false);
		$xp = LmsXpHelper::creditLessonComplete(
			(int)$u->id_admin,
			(int)$u->id,
			(int)$aula->id,
			(int)($lesson['durationMinutes'] ?? 0)
		);

		$cert = \App\Common\Helpers\LmsCertificadoHelper::emitirSeCursoCompleto(
			(int)$u->id,
			(int)$u->id_admin,
			$curso
		);

		\App\Common\Helpers\LmsNotificacaoHelper::criar(
			(int)$u->id_admin,
			(int)$u->id,
			'lesson',
			'Aula concluída',
			(string)($lesson['title'] ?? 'Aula').($xp > 0 ? ' (+'.$xp.' XP)' : ''),
			'/courses/'.(int)$curso->id.'/lessons/'.(int)$aula->id,
			'lesson:'.(int)$aula->id.':'.(int)$prog->ciclo
		);

		\App\Common\Helpers\LmsConquistaHelper::recalcular((int)$u->id_admin, (int)$u->id);

		return self::ok([
			'ok' => true,
			'xpEarned' => $xp,
			'cycle' => (int)$prog->ciclo,
			'certificateIssued' => $cert ? (string)$cert->id : null,
			'message' => (int)$prog->ciclo > 1
				? 'Aula revisada. Você ganhou +3 tentativas nas atividades.'
				: 'Aula concluída. Atividades liberadas.',
		]);
	}

	/**
	 * Progresso de aula interativa (cenas).
	 * Body: { passo, maxPasso?, concluida? }
	 */
	public static function interactiveProgress($request, $courseId, $lessonId) {
		$u = self::user($request);
		$curso = StudentEntitlement::cursoDoAluno($courseId, (int)$u->id, (int)$u->id_admin, !ctype_digit((string)$courseId));
		if (!$curso) {
			return self::err('Curso não encontrado ou sem acesso.', 404);
		}
		$aula = self::aulaDoCurso($curso, (int)$lessonId);
		if (!$aula) {
			return self::err('Aula não encontrada.', 404);
		}

		$body = $request->getPostVars() ?: [];
		if (!is_array($body)) {
			$body = [];
		}
		$passo = (int)($body['passo'] ?? $body['step'] ?? 0);
		$maxPasso = (int)($body['maxPasso'] ?? $body['max_passo'] ?? $passo);
		$concluida = !empty($body['concluida']) || !empty($body['completed']) ? 1 : 0;

		try {
			\App\Model\Entity\LmsAulaInterativaProgresso::upsert(
				(int)$u->id,
				(int)$aula->id,
				$passo,
				$maxPasso,
				$concluida
			);
		} catch (\Throwable $e) {
			return self::err('Progresso interativo indisponível neste servidor.', 501);
		}

		$completePayload = null;
		if ($concluida) {
			$completePayload = self::completeLesson($request, $courseId, $lessonId);
			// Se complete falhar (ex.: agenda), ainda salvamos o progresso interativo
			$decoded = json_decode($completePayload['json'] ?? '{}', true);
			if (($completePayload['code'] ?? 200) >= 400) {
				return self::ok([
					'ok' => true,
					'progressSaved' => true,
					'lessonCompleted' => false,
					'completeError' => is_array($decoded) ? ($decoded['message'] ?? 'Falha ao concluir aula') : 'Falha ao concluir aula',
					'passo' => $passo,
					'maxPasso' => $maxPasso,
					'concluida' => 1,
				]);
			}
			return self::ok([
				'ok' => true,
				'progressSaved' => true,
				'lessonCompleted' => true,
				'complete' => is_array($decoded) ? $decoded : null,
				'passo' => $passo,
				'maxPasso' => $maxPasso,
				'concluida' => 1,
			]);
		}

		return self::ok([
			'ok' => true,
			'progressSaved' => true,
			'lessonCompleted' => false,
			'passo' => $passo,
			'maxPasso' => $maxPasso,
			'concluida' => 0,
		]);
	}

	public static function accessWindow($request) {
		$u = self::user($request);
		$idTrilha = LmsAgendaAcessoHelper::idTrilhaAgendaAluno((int)$u->id, (int)$u->id_admin);
		if ($idTrilha <= 0) {
			return self::ok([
				'active' => true,
				'message' => 'Curso EAD sem restrição de agenda.',
				'quotaMax' => 0,
				'quotaUsed' => 0,
				'quotaRemaining' => 0,
			]);
		}
		return self::ok(LmsAgendaAcessoHelper::accessWindow((int)$u->id, (int)$u->id_admin, $idTrilha));
	}

	public static function dashboard($request) {
		$listRes = self::listCourses($request);
		$courses = json_decode($listRes['json'], true) ?: [];
		$overall = 0;
		if (count($courses)) {
			$overall = (int)round(array_sum(array_column($courses, 'progressPercent')) / count($courses));
		}
		$current = null;
		foreach ($courses as $c) {
			if (($c['progressPercent'] ?? 0) > 0 && ($c['progressPercent'] ?? 0) < 100) {
				$current = $c;
				break;
			}
		}
		if (!$current && !empty($courses)) {
			$current = $courses[0];
		}
		$continueLesson = null;
		if ($current) {
			$lessonId = $current['lastAccessedLessonId'] ?? null;
			$found = null;
			foreach ($current['modules'] ?? [] as $m) {
				foreach ($m['lessons'] ?? [] as $l) {
					if ($lessonId && (string)$l['id'] === (string)$lessonId && empty($l['locked'])) {
						$found = $l;
						break 2;
					}
				}
			}
			if (!$found) {
				foreach ($current['modules'] ?? [] as $m) {
					foreach ($m['lessons'] ?? [] as $l) {
						if (empty($l['locked']) && empty($l['completed'])) {
							$found = $l;
							break 2;
						}
					}
				}
			}
			if (!$found) {
				foreach ($current['modules'] ?? [] as $m) {
					foreach ($m['lessons'] ?? [] as $l) {
						if (empty($l['locked'])) {
							$found = $l;
							break 2;
						}
					}
				}
			}
			if ($found) {
				$continueLesson = ['course' => $current, 'lesson' => $found];
			}
		}
		$u = StudentApiMapper::user(self::user($request));
		$userEntity = self::user($request);
		return self::ok([
			'coursesCount' => count($courses),
			'overallProgress' => $overall,
			'studyMinutes' => (int)($u['totalStudyMinutes'] ?? 0),
			'streakDays' => (int)($u['streakDays'] ?? 0),
			'currentCourse' => $current,
			'continueLesson' => $continueLesson,
			'recentCourses' => array_slice($courses, 0, 4),
			'notifications' => LmsNotificacaoHelper::listForApi((int)$userEntity->id_admin, (int)$userEntity->id, 5, true),
			'achievements' => LmsConquistaHelper::listForDashboard((int)$userEntity->id_admin, (int)$userEntity->id, 6),
			'ranking' => LmsXpHelper::rankingEscola((int)$userEntity->id_admin, (int)$userEntity->id, 10)['entries'],
			'xp' => $u['xp'],
			'level' => $u['level'],
			'nextLevelXp' => $u['nextLevelXp'],
		]);
	}

	public static function ranking($request) {
		$u = self::user($request);
		$q = $request->getQueryParams() ?: [];
		$scope = strtolower(trim((string)($q['scope'] ?? 'school')));
		if ($scope === 'global') {
			$data = LmsXpHelper::rankingGlobal((int)$u->id, 50);
		} else {
			$data = LmsXpHelper::rankingEscola((int)$u->id_admin, (int)$u->id, 50);
		}
		return self::ok($data);
	}

	public static function achievements($request) {
		$u = self::user($request);
		return self::ok(LmsConquistaHelper::listForApi((int)$u->id_admin, (int)$u->id));
	}

	/** Presença geral no portal (logado, qualquer página). */
	public static function presence($request) {
		$u = self::user($request);
		$post = $request->getPostVars() ?: [];
		$path = trim((string)($post['path'] ?? $post['rota'] ?? ''));
		$idCurso = isset($post['courseId']) ? (int)$post['courseId'] : (isset($post['id_curso']) ? (int)$post['id_curso'] : null);
		$idAula = isset($post['lessonId']) ? (int)$post['lessonId'] : (isset($post['id_aula']) ? (int)$post['id_aula'] : null);
		$res = LmsPresencaHelper::ping(
			(int)$u->id_admin,
			(int)$u->id,
			$path,
			$idCurso,
			$idAula
		);
		if (empty($res['ok'])) {
			$code = !empty($res['message']) && strpos((string)$res['message'], 'sql') !== false ? 501 : 400;
			return self::err((string)($res['message'] ?? 'Falha na presença.'), $code);
		}
		return self::ok(['ok' => true]);
	}

	/** Heartbeat de tempo de estudo (player Ascend). */
	public static function studyHeartbeat($request) {
		$u = self::user($request);
		$post = $request->getPostVars() ?: [];
		$idAula = (int)($post['lessonId'] ?? $post['id_aula'] ?? 0);
		$idCurso = (int)($post['courseId'] ?? $post['id_curso'] ?? 0);
		$sessionId = isset($post['sessionId']) ? (int)$post['sessionId'] : null;
		$origem = trim((string)($post['origin'] ?? $post['origem'] ?? 'presence'));

		if ($idAula <= 0) {
			return self::err('Informe a aula.');
		}
		if ($idCurso > 0) {
			$curso = StudentEntitlement::cursoDoAluno((string)$idCurso, (int)$u->id, (int)$u->id_admin, false);
			if (!$curso) {
				return self::err('Curso sem acesso.', 403);
			}
			$aula = self::aulaDoCurso($curso, $idAula);
			if (!$aula) {
				return self::err('Aula não encontrada.', 404);
			}
		} else {
			$aula = LmsAula::getById($idAula);
			if (!$aula) {
				return self::err('Aula não encontrada.', 404);
			}
			$idCurso = null;
		}

		$res = LmsEstudoHelper::heartbeat(
			(int)$u->id_admin,
			(int)$u->id,
			$idAula,
			$idCurso,
			$origem,
			$sessionId
		);
		if (empty($res['ok']) && !empty($res['message'])) {
			$code = strpos((string)$res['message'], 'sql') !== false ? 501 : 400;
			return self::err((string)$res['message'], $code);
		}
		return self::ok($res);
	}

	public static function listComments($request, $courseId, $lessonId) {
		$u = self::user($request);
		$curso = StudentEntitlement::cursoDoAluno($courseId, (int)$u->id, (int)$u->id_admin, !ctype_digit((string)$courseId));
		if (!$curso) {
			return self::err('Curso não encontrado ou sem acesso.', 404);
		}
		$aula = self::aulaDoCurso($curso, (int)$lessonId);
		if (!$aula) {
			return self::err('Aula não encontrada.', 404);
		}
		if (!LmsComentarioHelper::tabelasExistem()) {
			return self::ok([]);
		}
		return self::ok(LmsComentarioHelper::listForApi((int)$u->id_admin, (int)$aula->id));
	}

	public static function postComment($request, $courseId, $lessonId) {
		$u = self::user($request);
		$curso = StudentEntitlement::cursoDoAluno($courseId, (int)$u->id, (int)$u->id_admin, !ctype_digit((string)$courseId));
		if (!$curso) {
			return self::err('Curso não encontrado ou sem acesso.', 404);
		}
		$aula = self::aulaDoCurso($curso, (int)$lessonId);
		if (!$aula) {
			return self::err('Aula não encontrada.', 404);
		}
		$post = $request->getPostVars() ?: [];
		$texto = (string)($post['text'] ?? $post['texto'] ?? '');
		$idPai = isset($post['parentId']) ? (int)$post['parentId'] : (isset($post['id_pai']) ? (int)$post['id_pai'] : null);
		$res = LmsComentarioHelper::criar(
			(int)$u->id_admin,
			(int)$u->id,
			(int)$aula->id,
			(int)$curso->id,
			$texto,
			$idPai
		);
		if (empty($res['ok'])) {
			return self::err($res['message'] ?? 'Falha ao comentar.', 400);
		}
		return self::ok($res);
	}

	public static function deleteComment($request, $courseId, $lessonId, $commentId) {
		$u = self::user($request);
		$curso = StudentEntitlement::cursoDoAluno($courseId, (int)$u->id, (int)$u->id_admin, !ctype_digit((string)$courseId));
		if (!$curso) {
			return self::err('Curso não encontrado ou sem acesso.', 404);
		}
		$res = LmsComentarioHelper::excluir((int)$u->id_admin, (int)$u->id, (int)$commentId, false);
		if (empty($res['ok'])) {
			return self::err($res['message'] ?? 'Falha ao excluir.', 400);
		}
		return self::ok(['ok' => true]);
	}

	public static function getNotes($request, $courseId, $lessonId) {
		$u = self::user($request);
		$curso = StudentEntitlement::cursoDoAluno($courseId, (int)$u->id, (int)$u->id_admin, !ctype_digit((string)$courseId));
		if (!$curso) {
			return self::err('Curso não encontrado ou sem acesso.', 404);
		}
		$aula = self::aulaDoCurso($curso, (int)$lessonId);
		if (!$aula) {
			return self::err('Aula não encontrada.', 404);
		}
		return self::ok(LmsAnotacaoHelper::getForApi((int)$u->id_admin, (int)$u->id, (int)$aula->id));
	}

	public static function putNotes($request, $courseId, $lessonId) {
		$u = self::user($request);
		$curso = StudentEntitlement::cursoDoAluno($courseId, (int)$u->id, (int)$u->id_admin, !ctype_digit((string)$courseId));
		if (!$curso) {
			return self::err('Curso não encontrado ou sem acesso.', 404);
		}
		$aula = self::aulaDoCurso($curso, (int)$lessonId);
		if (!$aula) {
			return self::err('Aula não encontrada.', 404);
		}
		$post = $request->getPostVars() ?: [];
		$texto = (string)($post['text'] ?? $post['texto'] ?? '');
		$res = LmsAnotacaoHelper::salvar(
			(int)$u->id_admin,
			(int)$u->id,
			(int)$aula->id,
			(int)$curso->id,
			$texto
		);
		if (empty($res['ok'])) {
			$code = strpos((string)($res['message'] ?? ''), 'sql') !== false ? 501 : 400;
			return self::err($res['message'] ?? 'Falha ao salvar anotação.', $code);
		}
		return self::ok($res);
	}

	public static function playVideo($request, $courseId, $lessonId, $videoId) {
		$u = self::user($request);
		$curso = StudentEntitlement::cursoDoAluno($courseId, (int)$u->id, (int)$u->id_admin, !ctype_digit((string)$courseId));
		if (!$curso) {
			return self::err('Curso não encontrado ou sem acesso.', 404);
		}
		$aula = self::aulaDoCurso($curso, (int)$lessonId);
		if (!$aula) {
			return self::err('Aula não encontrada.', 404);
		}
		$idOwner = StudentEntitlement::idAdminConteudo($curso);
		$v = LmsVideo::getByIdAdmin((int)$videoId, $idOwner);
		if (!$v || (int)$v->id_aula !== (int)$aula->id) {
			return self::err('Vídeo não encontrado.', 404);
		}
		if (($v->provider ?? '') !== 'bunny' || empty($v->bunny_video_id)) {
			return self::err('Vídeo Bunny indisponível.', 404);
		}
		if (($v->bunny_status ?? '') !== 'ready') {
			BunnyStreamHelper::sincronizarStatusVideo($v, $idOwner);
			$v = LmsVideo::getByIdAdmin((int)$videoId, $idOwner) ?: $v;
		}
		if (($v->bunny_status ?? '') !== 'ready') {
			return self::err(
				(($v->bunny_status ?? '') === 'error')
					? 'Vídeo Bunny com erro no encode. Reenvie no painel.'
					: 'Vídeo Bunny ainda processando. Tente em instantes.',
				409
			);
		}
		$play = BunnyStreamHelper::urlPlayback($idOwner, (string)$v->bunny_video_id, 7200);
		if (empty($play['ok'])) {
			return self::err($play['message'] ?? 'Falha ao assinar playback.', 501);
		}
		return self::ok([
			'playbackUrl' => $play['playbackUrl'],
			'expiresAt' => (int)$play['expiresAt'],
			'provider' => 'bunny',
		]);
	}
}
