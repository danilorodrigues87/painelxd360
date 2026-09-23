<?php

namespace App\Common\Helpers;

use App\Model\Entity\User;
use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsModulo;
use App\Model\Entity\LmsAula;
use App\Model\Entity\LmsAulaCena;
use App\Model\Entity\LmsVideo;
use App\Model\Entity\LmsMaterial;
use App\Model\Entity\LmsProgressoAula;
use App\Model\Entity\LmsAulaInterativaProgresso;
use App\Model\Entity\LmsAtividade;
use App\Model\Entity\LmsQuestao;
use App\Model\Entity\LmsRoleplayCenario;
use App\Model\Entity\LmsAtividadeTentativa;
use App\Model\Entity\LmsRoleplaySessao;
use App\Model\Entity\CategoryCourses;
use App\Model\Entity\Trilhas;
use App\Model\Entity\EstadoCidades;
use App\Model\Entity\LmsCursoAvaliacao;
use App\Common\Helpers\UserFotoHelper;

/**
 * Converte rows LMS → shapes Ascend (camelCase).
 */
class StudentApiMapper {

	public static function user(User $u): array {
		$idAdmin = (int)$u->id_admin;
		$idAluno = (int)$u->id;
		$xp = LmsXpHelper::totalAluno($idAluno, $idAdmin);
		$level = LmsXpHelper::levelFromXp($xp);
		try {
			LmsStreakHelper::creditXpSeSessaoHoje($idAdmin, $idAluno);
			$xp = LmsXpHelper::totalAluno($idAluno, $idAdmin);
			$level = LmsXpHelper::levelFromXp($xp);
			$streak = LmsStreakHelper::streakDays($idAluno, $idAdmin);
		} catch (\Throwable $e) {
			$streak = 0;
		}
		return [
			'id' => (string)$u->id,
			'name' => (string)$u->nome,
			'email' => (string)$u->email,
			'phone' => $u->whatsapp ?? null,
			'city' => self::cidadeLabel($u->cidade ?? null, $u->uf ?? null),
			'avatarUrl' => self::avatarUrl($u),
			'role' => 'student',
			'xp' => $xp,
			'level' => $level,
			'nextLevelXp' => LmsXpHelper::xpForNextLevel($level),
			'streakDays' => $streak,
			'totalStudyMinutes' => LmsEstudoHelper::minutosAluno($idAluno, $idAdmin),
			'createdAt' => date('c'),
		];
	}

	/** Nome da cidade (+ UF) a partir dos IDs em usuarios.cidade / usuarios.uf. */
	public static function cidadeLabel($cidadeId, $ufId = null): ?string {
		$cidadeId = (int)$cidadeId;
		if ($cidadeId <= 0) {
			return null;
		}
		$cidade = EstadoCidades::getCidades('id = '.$cidadeId)->fetchObject();
		if (!is_object($cidade)) {
			return null;
		}
		$nome = trim((string)($cidade->nome ?? ''));
		if ($nome === '') {
			return null;
		}
		$estadoId = (int)($ufId ?: ($cidade->estados_id ?? 0));
		$sigla = '';
		if ($estadoId > 0) {
			$est = EstadoCidades::getEstados('id = '.$estadoId)->fetchObject();
			if (is_object($est)) {
				$sigla = trim((string)($est->sigla ?? ''));
			}
		}
		return $sigla !== '' ? $nome.'/'.$sigla : $nome;
	}

	/** URL pública da foto; null = portal mostra iniciais. */
	public static function avatarUrl(User $u): ?string {
		if (!User::temColunaFoto()) {
			return null;
		}
		$foto = trim((string)($u->foto ?? ''));
		if ($foto === '' || strpos($foto, '..') !== false || strpos($foto, '/') !== false) {
			return null;
		}
		return UserFotoHelper::urlPublica($foto);
	}

	public static function tokens(string $accessToken, int $expiresIn = 86400): array {
		return [
			'accessToken' => $accessToken,
			'refreshToken' => $accessToken,
			'expiresIn' => $expiresIn,
		];
	}

	public static function course(LmsCurso $curso, int $idAluno, int $idAdmin, bool $withModules = true): array {
		$nome = $curso->nomeExibicao();
		$carga = (int)($curso->carga_h ?? 0);
		$catName = '';
		if (!empty($curso->id_trilha)) {
			$trilha = Trilhas::getTrilhaById((int)$curso->id_trilha);
			if ($trilha && empty($carga)) {
				$carga = (int)$trilha->carga_h;
			}
			if ($trilha && !empty($trilha->id_categoria)) {
				$cat = CategoryCourses::getCategoryById((int)$trilha->id_categoria);
				if ($cat) {
					$catName = (string)$cat->nome;
				}
			}
		}
		$objectives = json_decode((string)($curso->objectives ?? '[]'), true);
		if (!is_array($objectives)) {
			$objectives = [];
		}

		// Conteúdo pertence ao criador do curso (vitrine cross-tenant)
		$idOwner = (int)$curso->id_admin;

		$modules = [];
		$lessonsCount = 0;
		$completedCount = 0;
		$curriculumCount = 0;
		$curriculumDone = 0;
		$lastAccessed = null;
		$progressMap = [];
		foreach (LmsProgressoAula::listByAluno($idAluno, $idAdmin) as $p) {
			$progressMap[(int)$p->id_aula] = $p;
			if ($p->ultimo_acesso && ($lastAccessed === null || $p->ultimo_acesso > $lastAccessed['at'])) {
				$lastAccessed = ['id' => (string)$p->id_aula, 'at' => $p->ultimo_acesso];
			}
		}

		$assessmentDone = self::assessmentDoneMap($idAluno);
		$roleplayDone = self::roleplayDoneMap($idAluno, $idAdmin);

		// Agenda pela trilha/plano da escola do aluno (inclui curso licenciado da vitrine).
		$idTrilha = LmsAgendaAcessoHelper::idTrilhaAgendaAluno($idAluno, (int)$idAdmin);
		$usaAgenda = $idTrilha > 0;
		$idsIncompletas = LmsAgendaAcessoHelper::idsIncompletasDoCurso($curso, $idAluno, $idOwner, $idAdmin);
		$janela = $usaAgenda ? LmsAgendaAcessoHelper::janelaAtiva($idAluno, $idAdmin, $idTrilha) : null;
		$consumidas = $usaAgenda ? LmsAgendaAcessoHelper::aulasConsumidasHoje($idAluno, $idAdmin) : [];
		$cotaMax = $janela ? (int)$janela['aulas_cota'] : 0;
		$slots = $janela ? max(0, $cotaMax - count($consumidas)) : 0;
		$aulasAgendaOk = [];
		if ($usaAgenda) {
			foreach ($idsIncompletas as $idInc) {
				if (in_array($idInc, $consumidas, true)) {
					$aulasAgendaOk[$idInc] = true;
					continue;
				}
				if ($slots > 0) {
					$aulasAgendaOk[$idInc] = true;
					$slots--;
				}
			}
			$accessWindow = LmsAgendaAcessoHelper::accessWindow($idAluno, $idAdmin, $idTrilha);
		} else {
			foreach ($idsIncompletas as $idInc) {
				$aulasAgendaOk[$idInc] = true;
			}
			$accessWindow = [
				'active' => true,
				'window' => null,
				'quotaMax' => 0,
				'quotaUsed' => 0,
				'quotaRemaining' => 0,
				'consumedLessonIds' => [],
				'nextWindow' => null,
				'message' => 'Curso EAD sem restrição de agenda.',
			];
			$janela = ['label' => 'EAD livre'];
		}

		$prevUnidadeOk = true; // 1ª aula do módulo libera se não bloqueada no admin

		foreach (LmsModulo::listByCurso((int)$curso->id, $idOwner) as $mod) {
			$lessons = [];
			$curriculum = [];
			$prevUnidadeOk = true;
			foreach (LmsAula::listByModulo((int)$mod->id, $idOwner) as $aula) {
				$lessonsCount++;
				$prog = $progressMap[(int)$aula->id] ?? null;
				$precisaRevisar = $prog && (int)($prog->precisa_revisar ?? 0) === 1;
				$unidadeOk = $prog && (int)($prog->unidade_aprovada ?? 0) === 1;
				$assistida = $prog && !empty($prog->concluida_em) && !$precisaRevisar;
				$ciclo = $prog ? max(1, (int)($prog->ciclo ?? 1)) : 1;

				$adminLocked = ((int)($aula->bloqueado ?? 0) === 1);
				$draftInteractive = LmsAula::temColunaInterativa()
					&& (string)($aula->tipo_conteudo ?? 'video') === 'interativa'
					&& (string)($aula->interativa_status ?? 'rascunho') !== 'publicada';
				$semAvaliacao = count(LmsUnidadeAvaliacaoHelper::itensAvaliados((int)$aula->id, $idOwner)) === 0;
				$completed = $unidadeOk || ($semAvaliacao && $assistida);
				$revisaoLivre = $completed || $precisaRevisar;
				$lessonLocked = $adminLocked;
				$lockReason = null;
				$lockMessage = null;
				if (!$revisaoLivre && !$adminLocked) {
					if (!$prevUnidadeOk) {
						$lessonLocked = true;
						$lockReason = 'sequencia';
						$lockMessage = 'Conclua a unidade anterior para liberar.';
					} elseif ($draftInteractive) {
						$lessonLocked = true;
						$lockReason = 'rascunho';
						$lockMessage = 'Esta aula ainda está em preparação.';
					} elseif (empty($aulasAgendaOk[(int)$aula->id])) {
						$lessonLocked = true;
						if (!$janela) {
							$lockReason = 'fora_horario';
							$lockMessage = $accessWindow['message'] ?? 'Fora do horário agendado.';
						} else {
							$lockReason = 'cota_esgotada';
							$lockMessage = 'Cota de aulas desta sessão esgotada.';
						}
					}
				}
				if ($completed) {
					$completedCount++;
				}
				$lessonPayload = self::lesson($aula, (int)$mod->id, $idOwner, $assistida || $unidadeOk, $lessonLocked);
				if (!empty($lessonPayload['locked']) && !empty($lessonPayload['lockReason'])) {
					$lessonLocked = true;
					$lockReason = (string)$lessonPayload['lockReason'];
					$lockMessage = (string)($lessonPayload['lockMessage'] ?? $lockMessage);
				}
				$lessonPayload['needsRewatch'] = $precisaRevisar;
				$lessonPayload['unitScore'] = $prog && $prog->nota_unidade !== null ? (float)$prog->nota_unidade : null;
				$lessonPayload['unitPassed'] = $unidadeOk;
				$lessonPayload['cycle'] = $ciclo;
				$lessonPayload['lockReason'] = $lockReason;
				$lessonPayload['lockMessage'] = $lockMessage;
				$lessonPayload['locked'] = $lessonLocked;
				if (!empty($lessonPayload['contentType']) && $lessonPayload['contentType'] === 'interactive') {
					$lessonPayload['interactiveProgress'] = self::interactiveProgressPayload($idAluno, (int)$aula->id);
				}
				$lessons[] = $lessonPayload;

				$aulaCurriculum = [];
				$aulaCurriculum[] = [
					'kind' => 'lesson',
					'id' => (string)$aula->id,
					'title' => (string)$aula->titulo,
					'order' => (int)$aula->ordem,
					'durationMinutes' => (int)$lessonPayload['durationMinutes'],
					'completed' => $assistida || $unidadeOk,
					'locked' => $lessonLocked,
					'needsRewatch' => $precisaRevisar,
					'unitScore' => $lessonPayload['unitScore'],
					'unitPassed' => $unidadeOk,
					'cycle' => $ciclo,
					'lockReason' => $lockReason,
					'lockMessage' => $lockMessage,
				];

				foreach (LmsAtividade::listByAula((int)$aula->id, $idOwner) as $at) {
					$notaCiclo = LmsUnidadeAvaliacaoHelper::melhorNotaAtividade($idAluno, (int)$at->id, $ciclo);
					// "completed" na sequência = já fez pelo menos 1 tentativa no ciclo (não exige ≥70 individual)
					$doneSeq = $notaCiclo !== null;
					$aulaCurriculum[] = [
						'kind' => 'assessment',
						'id' => (string)$at->id,
						'title' => (string)$at->titulo,
						'order' => (int)$at->ordem,
						'durationMinutes' => (int)$at->duracao_min,
						'completed' => $doneSeq,
						'locked' => false,
						'score' => $notaCiclo,
					];
				}

				foreach (LmsRoleplayCenario::listByAula((int)$aula->id, $idOwner) as $rp) {
					$notaCiclo = LmsUnidadeAvaliacaoHelper::melhorNotaRoleplay($idAluno, (int)$rp->id, $idAdmin, $ciclo);
					$doneSeq = $notaCiclo !== null;
					$aulaCurriculum[] = [
						'kind' => 'roleplay',
						'id' => (string)$rp->id,
						'title' => (string)$rp->titulo,
						'order' => 0,
						'durationMinutes' => (int)$rp->estimated_minutes,
						'completed' => $doneSeq,
						'locked' => false,
						'score' => $notaCiclo,
					];
				}

				// Sequência: aula assistida libera atividades; cada item feito libera o próximo
				$lessonReady = ($assistida || $unidadeOk) && !$precisaRevisar;
				foreach ($aulaCurriculum as $idx => &$it) {
					if ($it['kind'] !== 'lesson') {
						if (!$lessonReady) {
							$it['locked'] = true;
						} else {
							$prev = $aulaCurriculum[$idx - 1];
							$it['locked'] = empty($prev['completed']);
						}
					}
					$curriculum[] = $it;
					$curriculumCount++;
					if (!empty($it['completed'])) {
						$curriculumDone++;
					}
				}
				unset($it);

				$prevUnidadeOk = $unidadeOk || ($semAvaliacao && $assistida);
			}

			foreach (LmsRoleplayCenario::listByModuloSemAula((int)$mod->id, $idOwner) as $rp) {
				$done = !empty($roleplayDone[(int)$rp->id]);
				$curriculum[] = [
					'kind' => 'roleplay',
					'id' => (string)$rp->id,
					'title' => (string)$rp->titulo,
					'order' => 999,
					'durationMinutes' => (int)$rp->estimated_minutes,
					'completed' => $done,
					'locked' => false,
				];
				$curriculumCount++;
				if ($done) {
					$curriculumDone++;
				}
			}

			if ($withModules) {
				$modules[] = [
					'id' => (string)$mod->id,
					'courseId' => (string)$curso->id,
					'title' => (string)$mod->titulo,
					'order' => (int)$mod->ordem,
					'locked' => ((int)($mod->bloqueado ?? 0) === 1),
					'lessons' => $lessons,
					'curriculum' => $curriculum,
				];
			}
		}

		$progressPercent = $lessonsCount > 0 ? (int)round(($completedCount / $lessonsCount) * 100) : 0;
		$desc = $curso->short_description ?? '';
		if (!empty($curso->id_trilha)) {
			$trilhaDesc = Trilhas::getTrilhaById((int)$curso->id_trilha);
			if ($trilhaDesc && !empty($trilhaDesc->descricao)) {
				$desc = $trilhaDesc->descricao;
			}
		}

		$rating = LmsCursoAvaliacao::mediaCurso((int)$curso->id, $idOwner);
		$myRating = LmsCursoAvaliacao::getByAlunoCurso($idAluno, (int)$curso->id);

		return [
			'id' => (string)$curso->id,
			'slug' => (string)$curso->slug,
			'title' => $nome,
			'description' => strip_tags((string)$desc),
			'shortDescription' => (string)($curso->short_description ?: mb_substr(strip_tags((string)$desc), 0, 160)),
			'coverUrl' => ($curso->cover_url ?: null),
			'bannerUrl' => ($curso->banner_url ?: null),
			'instructor' => [
				'id' => 'inst_'.(int)$curso->id,
				'name' => (string)($curso->instructor_name ?: 'Instrutor'),
				'avatarUrl' => self::safeUrl($curso->instructor_avatar_url ?? null),
				'title' => $curso->instructor_title ?: null,
				'bio' => $curso->instructor_bio ?: null,
			],
			'categories' => $catName !== '' ? [$catName] : [],
			'level' => (string)($curso->level ?: 'Iniciante'),
			'workloadHours' => $carga,
			'estimatedMinutes' => $carga * 60,
			'rating' => $rating['avg'],
			'ratingCount' => $rating['count'],
			'myRating' => $myRating ? (int)$myRating->nota : null,
			'progressPercent' => $progressPercent,
			'objectives' => $objectives,
			'modulesCount' => count($modules),
			'lessonsCount' => $lessonsCount,
			'curriculumCount' => $curriculumCount,
			'curriculumCompleted' => $curriculumDone,
			'accessWindow' => $accessWindow,
			'modules' => $withModules ? $modules : [],
			'enrolled' => true,
			'lastAccessedLessonId' => $lastAccessed['id'] ?? null,
		];
	}

	private static function safeUrl($url): ?string {
		$url = is_string($url) ? trim($url) : '';
		if ($url === '' || !preg_match('#^https?://#i', $url)) {
			return null;
		}
		return $url;
	}

	private static function assessmentDoneMap(int $idAluno): array {
		$map = [];
		try {
			foreach (LmsAtividadeTentativa::listPassedByAluno($idAluno) as $t) {
				$map[(int)$t->id_atividade] = true;
			}
		} catch (\Throwable $e) {
			/* tabela / método ausente */
		}
		return $map;
	}

	private static function roleplayDoneMap(int $idAluno, int $idAdmin): array {
		$map = [];
		try {
			foreach (LmsRoleplaySessao::listByAluno($idAluno, $idAdmin) as $s) {
				if (!empty($s->ended_at) || ($s->status ?? '') === 'finished' || $s->score !== null) {
					if (!empty($s->id_cenario)) {
						$map[(int)$s->id_cenario] = true;
					}
				}
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
		return $map;
	}

	public static function lesson(LmsAula $aula, int $moduleId, int $idAdmin, bool $completed, bool $locked): array {
		$isInteractive = LmsAula::temColunaInterativa()
			&& (string)($aula->tipo_conteudo ?? 'video') === 'interativa';

		$videos = [];
		$totalMin = 0;
		if (!$isInteractive) {
			foreach (LmsVideo::listByAula((int)$aula->id, $idAdmin) as $v) {
				$totalMin += (int)$v->duracao_min;
				$provider = (string)($v->provider ?: 'youtube');
				if ($provider === 'bunny') {
					$status = (string)($v->bunny_status ?? '');
					if ($status !== 'ready' && !empty($v->bunny_video_id)) {
						$status = BunnyStreamHelper::sincronizarStatusVideo($v, $idAdmin);
					}
					if ($status !== 'ready' || empty($v->bunny_video_id)) {
						$videos[] = [
							'id' => (string)$v->id,
							'title' => (string)($v->titulo ?: 'Vídeo'),
							'url' => null,
							'provider' => 'bunny',
							'bunnyStatus' => !empty($v->bunny_video_id) ? ($status ?: 'processing') : 'pending_upload',
							'durationMinutes' => (int)$v->duracao_min,
							'order' => (int)$v->ordem,
						];
						continue;
					}
					// URL permanente não é exposta — Ascend chama /play
					$videos[] = [
						'id' => (string)$v->id,
						'title' => (string)($v->titulo ?: 'Vídeo'),
						'url' => null,
						'provider' => 'bunny',
						'bunnyStatus' => 'ready',
						'durationMinutes' => (int)$v->duracao_min,
						'order' => (int)$v->ordem,
					];
					continue;
				}
				$url = LmsHelper::normalizeVideoUrl((string)$v->url, $provider);
				$videos[] = [
					'id' => (string)$v->id,
					'title' => (string)($v->titulo ?: 'Vídeo'),
					'url' => $url,
					'provider' => $provider,
					'durationMinutes' => (int)$v->duracao_min,
					'order' => (int)$v->ordem,
				];
			}
		}
		$resources = [];
		foreach (LmsMaterial::listByAula((int)$aula->id, $idAdmin) as $m) {
			$resources[] = [
				'id' => (string)$m->id,
				'label' => (string)$m->label,
				'url' => (string)$m->url,
				'type' => (string)$m->tipo,
			];
		}
		$first = $videos[0] ?? null;
		$out = [
			'id' => (string)$aula->id,
			'moduleId' => (string)$moduleId,
			'title' => (string)$aula->titulo,
			'description' => $aula->descricao ?: null,
			'durationMinutes' => $totalMin,
			'videoUrl' => $first['url'] ?? null,
			'videoProvider' => $first['provider'] ?? null,
			'videos' => $videos,
			'completed' => $completed,
			'locked' => $locked,
			'order' => (int)$aula->ordem,
			'resources' => $resources,
		];

		if ($isInteractive) {
			$out['contentType'] = 'interactive';
			$out['voice'] = (string)($aula->voz_narracao ?: 'alloy');
			$out['interactiveStatus'] = (string)($aula->interativa_status ?? 'rascunho');
			if (LmsAula::temColunaInterativaAutoNarracao()) {
				$out['autoNarration'] = !empty($aula->interativa_auto_narracao);
			}
			if (LmsAula::temColunaInterativaDelayMs()) {
				$out['defaultRevealDelayMs'] = max(0, (int)($aula->interativa_delay_ms ?? 2000));
			}
			if (LmsAula::temColunaInterativaDuracaoMs()) {
				$out['defaultSceneDurationMs'] = max(0, (int)($aula->interativa_duracao_ms ?? 4000));
			}
			$out['videos'] = [];
			$out['videoUrl'] = null;
			$out['videoProvider'] = null;
			$published = (string)($aula->interativa_status ?? 'rascunho') === 'publicada';
			if (!$published) {
				$out['locked'] = true;
				$out['lockReason'] = 'rascunho';
				$out['lockMessage'] = 'Esta aula ainda está em preparação.';
				$out['scenes'] = [];
				return $out;
			}
			$scenes = [];
			foreach (LmsAulaCena::listByAula((int)$aula->id, $idAdmin) as $cena) {
				$interacao = $cena->interacao;
				if (is_string($interacao)) {
					$decoded = json_decode($interacao, true);
					$interacao = is_array($decoded) ? $decoded : [];
				}
				if (!is_array($interacao)) {
					$interacao = [];
				}
				if (!empty($interacao['object']) && is_string($interacao['object'])) {
					$obj = trim($interacao['object']);
					if ($obj !== '' && preg_match('#^https?://#i', $obj)) {
						$objClient = \App\Common\Helpers\BunnyStorageHelper::clientMediaUrl($obj, 'image');
						if ($objClient !== null && $objClient !== '') {
							$interacao['object'] = $objClient;
						}
					}
				}
				$src = (string)$cena->media_url;
				$bunnyVid = trim((string)($cena->media_bunny_video_id ?? ''));
				if ($bunnyVid !== '') {
					$play = \App\Common\Helpers\BunnyStreamHelper::urlPlayback($idAdmin, $bunnyVid, 7200);
					if (!empty($play['playbackUrl'])) {
						$src = (string)$play['playbackUrl'];
					}
				} elseif ($src !== '') {
					$kind = (string)($cena->media_kind ?: 'image');
					$client = \App\Common\Helpers\BunnyStorageHelper::clientMediaUrl($src, $kind);
					if ($client !== null && $client !== '') {
						$src = $client;
					}
				}
				$item = [
					'id' => (string)$cena->id,
					'media' => [
						'kind' => (string)($cena->media_kind ?: 'image'),
						'src' => $src,
					],
					'autoAdvance' => !empty($cena->auto_advance),
					'instruction' => (string)($cena->instrucao ?? ''),
					'hideInstructionBox' => LmsAulaCena::temColunaOcultarInstrucao() && !empty($cena->ocultar_instrucao),
					'tone' => (string)($cena->tone ?: 'light'),
					'interaction' => $interacao,
				];
				if (LmsAulaCena::temColunaAutoNarracao() && $cena->auto_narracao !== null && $cena->auto_narracao !== '') {
					$item['autoNarration'] = !empty($cena->auto_narracao);
				}
				if (LmsAulaCena::temColunaDelayRevelar() && $cena->delay_revelar_ms !== null && $cena->delay_revelar_ms !== '') {
					$item['revealDelayMs'] = max(0, (int)$cena->delay_revelar_ms);
				}
				if (LmsAulaCena::temColunaDuracaoMs() && $cena->duracao_ms !== null && $cena->duracao_ms !== '') {
					$item['sceneDurationMs'] = max(0, (int)$cena->duracao_ms);
				}
				if (!empty($cena->narracao_url)) {
					$narr = (string)$cena->narracao_url;
					$client = \App\Common\Helpers\BunnyStorageHelper::clientMediaUrl($narr, 'audio');
					$item['narrationUrl'] = ($client !== null && $client !== '') ? $client : $narr;
				}
				if ($bunnyVid !== '') {
					$item['mediaBunnyVideoId'] = $bunnyVid;
				}
				$scenes[] = $item;
			}
			$out['scenes'] = $scenes;
		}

		return $out;
	}

	/** Progresso de cenas da aula interativa (null se sem registro). */
	public static function interactiveProgressPayload(int $idAluno, int $idAula): ?array {
		try {
			$row = LmsAulaInterativaProgresso::get($idAluno, $idAula);
		} catch (\Throwable $e) {
			return null;
		}
		if (!$row instanceof LmsAulaInterativaProgresso) {
			return null;
		}
		return [
			'passo' => (int)$row->passo,
			'maxPasso' => (int)$row->max_passo,
			'concluida' => (int)$row->concluida === 1,
			'updatedAt' => $row->atualizado_em ? (string)$row->atualizado_em : null,
		];
	}

	public static function assessment(LmsAtividade $at, int $idAdmin, bool $includeAnswers = false): array {
		$questions = [];
		foreach (LmsQuestao::listByAtividade((int)$at->id, $idAdmin) as $q) {
			$ops = json_decode((string)($q->opcoes ?? '[]'), true);
			if (!is_array($ops)) {
				$ops = [];
			}
			$options = [];
			foreach ($ops as $i => $op) {
				if (is_array($op)) {
					$options[] = [
						'id' => (string)($op['id'] ?? $op['value'] ?? $i),
						'label' => (string)($op['label'] ?? $op['text'] ?? $op['id'] ?? $i),
					];
				} else {
					$options[] = ['id' => (string)$op, 'label' => (string)$op];
				}
			}
			// V/F legado sem opções: injeta Verdadeiro/Falso
			if ((string)$q->tipo === 'boolean' && count($options) === 0) {
				$options = [
					['id' => 'true', 'label' => 'Verdadeiro'],
					['id' => 'false', 'label' => 'Falso'],
				];
			}
			$item = [
				'id' => (string)$q->id,
				'type' => (string)$q->tipo,
				'prompt' => (string)$q->enunciado,
				'options' => $options,
			];
			if ($includeAnswers) {
				$item['correctAnswer'] = $q->resposta_correta;
			}
			$questions[] = $item;
		}
		return [
			'id' => (string)$at->id,
			'courseId' => (string)$at->id_curso,
			'title' => (string)$at->titulo,
			'description' => (string)($at->descricao ?? ''),
			'durationMinutes' => (int)$at->duracao_min,
			'attempts' => (int)$at->tentativas_max,
			'questions' => $questions,
		];
	}

	public static function roleplayScenario(LmsRoleplayCenario $rp, string $courseTitle = '', string $moduleTitle = '', bool $forStudent = true): array {
		$obj = json_decode((string)($rp->objectives ?? '[]'), true);
		$crit = json_decode((string)($rp->criteria ?? '[]'), true);
		$charName = self::shortAiLabel((string)($rp->ai_character_name ?? ''), 'Personagem');
		$aiRole = self::shortAiLabel((string)($rp->ai_role ?? ''), 'Cliente');
		$userRole = trim((string)($rp->user_role ?? '')) ?: 'Aluno';
		$out = [
			'id' => (string)$rp->id,
			'courseId' => (string)$rp->id_curso,
			'courseTitle' => $courseTitle,
			'moduleTitle' => $moduleTitle,
			'title' => (string)$rp->titulo,
			'theme' => (string)($rp->tema ?? ''),
			'scenario' => (string)($rp->cenario ?? ''),
			'userRole' => mb_substr($userRole, 0, 80),
			'aiRole' => $aiRole,
			'aiCharacterName' => $charName,
			'aiCharacterAvatarUrl' => $rp->ai_character_avatar_url ?: null,
			'objectives' => is_array($obj) ? $obj : [],
			'criteria' => is_array($crit) ? $crit : [],
			'difficulty' => (string)($rp->difficulty ?: 'medium'),
			'minScore' => (int)$rp->min_score,
			'initialMessage' => (string)($rp->initial_message ?? ''),
			'estimatedMinutes' => (int)$rp->estimated_minutes,
		];
		if ($forStudent) {
			$out['basePrompt'] = '';
			$out['initialPersonality'] = '';
		} else {
			$out['basePrompt'] = (string)($rp->base_prompt ?? '');
			$out['initialPersonality'] = (string)($rp->initial_personality ?? '');
			$out['aiRole'] = (string)($rp->ai_role ?? $aiRole);
			$out['aiCharacterName'] = (string)($rp->ai_character_name ?: $charName);
		}
		return $out;
	}

	/** Evita vazar prompt longo no rótulo exibido ao aluno. */
	private static function shortAiLabel(string $raw, string $fallback): string {
		$s = trim($raw);
		if ($s === '') {
			return $fallback;
		}
		if (mb_strlen($s) <= 48 && !preg_match('/^você\s+é\b/iu', $s)) {
			return $s;
		}
		if (preg_match('/["“”\']([^"“”\']{2,40})["“”\']/', $s, $m)) {
			return trim($m[1]);
		}
		if (preg_match('/\b(Dona|Sr\.?|Sra\.?|Dr\.?)\s+[A-Za-zÀ-ú]+/u', $s, $m)) {
			return trim($m[0]);
		}
		return $fallback;
	}
}
