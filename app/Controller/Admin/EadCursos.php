<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\LmsHelper;
use App\Common\Helpers\BunnyStreamHelper;
use App\Common\CtiCatalog;
use App\Model\Entity\LmsEscolaCursoCti;
use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsModulo;
use App\Model\Entity\LmsAula;
use App\Model\Entity\LmsVideo;
use App\Model\Entity\LmsMaterial;
use App\Model\Entity\LmsAtividade;
use App\Model\Entity\LmsQuestao;
use App\Model\Entity\LmsRoleplayCenario;
use App\Model\Entity\LmsEditorToken;
use App\Model\Entity\LmsAulaCena;
use App\Model\Entity\PlataformaBunny;
use App\Common\Environment;

class EadCursos extends Page {

	private static function json(array $data): string {
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public static function index($request) {
		$idAdmin = TenantHelper::getIdAdmin();
		$user = \App\Session\User\Login::getUserLogedData();
		$acesso = $user['usuario']['acesso'] ?? [];
		$nivel = (string)($user['usuario']['nivel'] ?? '');
		$mostraVitrine = \App\Common\Helpers\LmsVitrineHelper::deveExibirParaEscola($idAdmin)
			&& (
				\App\Common\Helpers\ModuleGateHelper::podeAcessar('Vitrine de cursos', $idAdmin, is_array($acesso) ? $acesso : [])
				|| ($nivel === 'Diretor' && \App\Common\Helpers\ModuleGateHelper::podeAcessar('Cursos Online', $idAdmin, is_array($acesso) ? $acesso : []))
			);
		$content = View::render('admin/modules/ead/index', [
			'vitrine_banner_class' => $mostraVitrine ? '' : 'd-none',
		]);
		return parent::getPanel('Cursos Online', $content, 'portal_ead', $request);
	}

	public static function editor($request, $idCurso) {
		$idAdmin = TenantHelper::getIdAdmin();
		$idCurso = (int)$idCurso;
		$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
		if (!$curso) {
			$request->getRouter()->redirect('/painel/ead');
			return '';
		}
		$content = View::render('admin/modules/ead/editor', [
			'id_curso' => $idCurso,
			'nome_curso' => htmlspecialchars($curso->nomeExibicao(), ENT_QUOTES, 'UTF-8'),
		]);
		return parent::getPanel('Cursos Online', $content, 'portal_ead', $request);
	}

	public static function getInfo($request) {
		return self::dispatch($request->getPostVars());
	}

	public static function dispatch(array $post): string {
		$acao = $post['acao'] ?? '';

		if (!LmsHelper::tabelasExistem()) {
			return self::json([
				'success' => false,
				'sql_ok' => false,
				'message' => 'Execute o SQL database/lms_ead.sql no phpMyAdmin.',
			]);
		}

		$map = [
			'listar' => 'listar',
			'criar_curso' => 'criarCurso',
			'carregar_curso' => 'carregarCurso',
			'salvar_geral' => 'salvarGeral',
			'listar_aulas' => 'listarAulas',
			'salvar_aula' => 'salvarAula',
			'excluir_aula' => 'excluirAula',
			'salvar_video' => 'salvarVideo',
			'excluir_video' => 'excluirVideo',
			'bunny_criar_video' => 'bunnyCriarVideo',
			'bunny_upload_auth' => 'bunnyUploadAuth',
			'bunny_finalize' => 'bunnyFinalize',
			'bunny_status' => 'bunnyStatus',
			'salvar_material' => 'salvarMaterial',
			'excluir_material' => 'excluirMaterial',
			'salvar_atividade' => 'salvarAtividade',
			'excluir_atividade' => 'excluirAtividade',
			'salvar_questao' => 'salvarQuestao',
			'excluir_questao' => 'excluirQuestao',
			'salvar_roleplay' => 'salvarRoleplay',
			'excluir_roleplay' => 'excluirRoleplay',
			'carregar_aula' => 'carregarAula',
			'listar_matriculas_ead' => 'listarMatriculasEad',
			'buscar_alunos' => 'buscarAlunos',
			'matricular_ead' => 'matricularEad',
			'desmatricular_ead' => 'desmatricularEad',
			'abrir_editor' => 'abrirEditor',
		];

		if (!isset($map[$acao])) {
			return self::json(['success' => false, 'message' => 'Ação inválida.']);
		}

		$method = $map[$acao];
		return self::$method($post);
	}

	private static function listar(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$order = LmsCurso::temColunaTitulo() ? 'titulo ASC, id DESC' : 'id DESC';
		$results = LmsCurso::get('id_admin = '.$idAdmin, $order);
		$itens = [];
		while ($c = $results->fetchObject(LmsCurso::class)) {
			$status = LmsHelper::statusEad($c, $idAdmin);
			$itens[] = [
				'id_curso' => (int)$c->id,
				'nome' => $c->nomeExibicao(),
				'carga_h' => $c->carga_h,
				'status' => $status,
				'publicado' => (int)$c->publicado,
				'aulas' => LmsHelper::contagemAulasCurso((int)$c->id, $idAdmin),
				'vitrine_ativo' => (int)($c->vitrine_ativo ?? 0),
				'vitrine_preco_mensal' => (float)($c->vitrine_preco_mensal ?? 0),
			];
		}
		$bunnySql = LmsVideo::temColunasBunny();
		$bunnyStream = BunnyStreamHelper::pronto($idAdmin);
		$bunnyMotivo = null;
		if (!$bunnySql) {
			$bunnyMotivo = 'Execute database/lms_videos_bunny.sql no phpMyAdmin.';
		} elseif (!$bunnyStream) {
			$bunnyMotivo = PlataformaBunny::get()->streamDiagnostico() ?: 'Bunny Stream incompleto no Master.';
		}
		return self::json([
			'success' => true,
			'sql_ok' => true,
			'matricula_ead_ok' => \App\Model\Entity\LmsMatriculaEad::tabelaExiste(),
			'xp_ok' => \App\Common\Helpers\LmsXpHelper::tabelasExistem(),
			'bunny_ok' => $bunnyStream && $bunnySql,
			'bunny_motivo' => $bunnyMotivo,
			'itens' => $itens,
			'cti_itens' => self::listarCursosCtiEscola($idAdmin),
			'catalogo_cti_ok' => CtiCatalog::tabelasExistem(),
		]);
	}

	/** @return array<int, array<string, mixed>> */
	private static function listarCursosCtiEscola(int $idAdmin): array {
		if (!LmsEscolaCursoCti::tabelaExiste()) {
			return [];
		}
		$itens = [];
		foreach (LmsEscolaCursoCti::listarAtivosEscola($idAdmin) as $row) {
			$curso = LmsCurso::getById((int)($row['curso_id'] ?? 0));
			if (!$curso instanceof LmsCurso) {
				continue;
			}
			$idOwner = (int)$curso->id_admin;
			$itens[] = [
				'id_curso' => (int)$curso->id,
				'nome' => $curso->nomeExibicao(),
				'carga_h' => $curso->carga_h,
				'status' => LmsHelper::statusEad($curso, $idOwner),
				'publicado' => (int)$curso->publicado,
				'aulas' => LmsHelper::contagemAulasCurso((int)$curso->id, $idOwner),
				'somente_leitura' => true,
			];
		}
		return $itens;
	}

	private static function criarCurso(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$titulo = trim((string)($post['titulo'] ?? 'Novo curso'));
		$curso = LmsHelper::criarCursoIndependente($idAdmin, $titulo);
		if (!$curso) {
			return self::json(['success' => false, 'message' => 'Não foi possível criar o curso.']);
		}
		return self::json([
			'success' => true,
			'message' => 'Curso criado.',
			'id_curso' => (int)$curso->id,
		]);
	}

	private static function carregarCurso(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idCurso = (int)($post['id_curso'] ?? 0);
		$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
		if (!$curso) {
			return self::json(['success' => false, 'message' => 'Curso não encontrado.']);
		}
		LmsHelper::garantirModuloPadrao((int)$curso->id, $idAdmin);
		$objectives = json_decode((string)($curso->objectives ?? '[]'), true);
		if (!is_array($objectives)) {
			$objectives = [];
		}
		return self::json([
			'success' => true,
			'curso' => [
				'id' => (int)$curso->id,
				'titulo' => $curso->nomeExibicao(),
				'slug' => $curso->slug,
				'short_description' => $curso->short_description,
				'cover_url' => $curso->cover_url,
				'banner_url' => $curso->banner_url,
				'level' => $curso->level,
				'carga_h' => $curso->carga_h,
				'objectives' => $objectives,
				'objectives_text' => implode("\n", $objectives),
				'instructor_name' => $curso->instructor_name,
				'instructor_title' => $curso->instructor_title,
				'instructor_bio' => $curso->instructor_bio,
				'instructor_avatar_url' => $curso->instructor_avatar_url,
				'publicado' => (int)$curso->publicado,
				'vitrine_ativo' => (int)($curso->vitrine_ativo ?? 0),
				'vitrine_preco_mensal' => (float)($curso->vitrine_preco_mensal ?? 0),
				'vitrine_descricao' => $curso->vitrine_descricao ?? '',
				'vitrine_ok' => LmsCurso::temColunaVitrine(),
			],
		]);
	}

	private static function salvarGeral(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idCurso = (int)($post['id_curso'] ?? 0);
		$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
		if (!$curso) {
			return self::json(['success' => false, 'message' => 'Curso não encontrado.']);
		}

		$titulo = trim((string)($post['titulo'] ?? ''));
		if ($titulo === '') {
			return self::json(['success' => false, 'message' => 'Informe o título do curso.']);
		}
		$curso->titulo = $titulo;
		$slugIn = trim((string)($post['slug'] ?? ''));
		$slug = LmsHelper::slugify($slugIn !== '' ? $slugIn : $titulo);
		$curso->slug = LmsHelper::slugUnico($slug, $idAdmin, (int)$curso->id);
		$curso->short_description = trim((string)($post['short_description'] ?? ''));
		$curso->cover_url = trim((string)($post['cover_url'] ?? ''));
		$curso->banner_url = trim((string)($post['banner_url'] ?? ''));
		$curso->carga_h = ($post['carga_h'] ?? '') !== '' ? (int)$post['carga_h'] : null;
		$level = (string)($post['level'] ?? 'Iniciante');
		$curso->level = in_array($level, ['Iniciante', 'Intermediário', 'Avançado'], true) ? $level : 'Iniciante';
		$objText = (string)($post['objectives_text'] ?? '');
		$objs = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $objText) ?: [])));
		$curso->objectives = json_encode($objs, JSON_UNESCAPED_UNICODE);
		$curso->instructor_name = trim((string)($post['instructor_name'] ?? ''));
		$curso->instructor_title = trim((string)($post['instructor_title'] ?? ''));
		$curso->instructor_bio = trim((string)($post['instructor_bio'] ?? ''));
		$curso->instructor_avatar_url = trim((string)($post['instructor_avatar_url'] ?? ''));
		$curso->publicado = !empty($post['publicado']) ? 1 : 0;

		if (LmsCurso::temColunaVitrine()) {
			$curso->vitrine_ativo = !empty($post['vitrine_ativo']) ? 1 : 0;
			$curso->vitrine_preco_mensal = (float)str_replace(',', '.', (string)($post['vitrine_preco_mensal'] ?? 0));
			if ($curso->vitrine_preco_mensal < 0) {
				$curso->vitrine_preco_mensal = 0;
			}
			$curso->vitrine_descricao = trim((string)($post['vitrine_descricao'] ?? ''));
			// Gratuito permitido (preço 0). Curso precisa estar publicado para aparecer na vitrine.
			if ($curso->vitrine_ativo && (int)$curso->publicado !== 1) {
				return self::json(['success' => false, 'message' => 'Publique o curso no portal para disponibilizá-lo na vitrine.']);
			}
		}

		$curso->salvar();

		return self::json(['success' => true, 'message' => 'Dados gerais salvos.', 'id_curso' => (int)$curso->id, 'slug' => $curso->slug]);
	}

	private static function listarMatriculasEad(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idCurso = (int)($post['id_curso'] ?? 0);
		$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
		if (!$curso && LmsCurso::getById($idCurso)) {
			// curso licenciado: matrículas são da escola assinante
			$curso = LmsCurso::getById($idCurso);
			if (!$curso || !\App\Common\Helpers\LmsMatriculaEadHelper::escolaPodeUsarCurso($curso, $idAdmin)) {
				return self::json(['success' => false, 'message' => 'Curso não encontrado.']);
			}
		} elseif (!$curso) {
			return self::json(['success' => false, 'message' => 'Curso não encontrado.']);
		}
		if (!\App\Model\Entity\LmsMatriculaEad::tabelaExiste()) {
			return self::json(['success' => false, 'message' => 'Execute database/lms_ead_independente.sql', 'sql_ok' => false]);
		}
		$itens = [];
		foreach (\App\Model\Entity\LmsMatriculaEad::listByCurso($idCurso, $idAdmin) as $m) {
			$aluno = \App\Model\Entity\User::getUserById((int)$m->id_aluno);
			$itens[] = [
				'id' => (int)$m->id,
				'id_aluno' => (int)$m->id_aluno,
				'nome' => $aluno ? (string)$aluno->nome : '—',
				'email' => $aluno ? (string)$aluno->email : '',
				'inicio' => $m->inicio,
			];
		}
		return self::json(['success' => true, 'itens' => $itens]);
	}

	private static function buscarAlunos(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$q = trim((string)($post['q'] ?? ''));
		if (mb_strlen($q) < 2) {
			return self::json(['success' => true, 'itens' => []]);
		}
		$qEsc = addslashes($q);
		$stmt = \App\Model\Entity\User::getUser(
			"id_admin = {$idAdmin} AND nivel = 'Cliente' AND (nome LIKE '%{$qEsc}%' OR email LIKE '%{$qEsc}%')",
			'nome ASC',
			'20'
		);
		$itens = [];
		while ($u = $stmt->fetchObject(\App\Model\Entity\User::class)) {
			$itens[] = ['id' => (int)$u->id, 'nome' => (string)$u->nome, 'email' => (string)$u->email];
		}
		return self::json(['success' => true, 'itens' => $itens]);
	}

	private static function matricularEad(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$res = \App\Common\Helpers\LmsMatriculaEadHelper::matricular(
			$idAdmin,
			(int)($post['id_aluno'] ?? 0),
			(int)($post['id_curso'] ?? 0),
			\App\Common\Helpers\LmsMatriculaEadHelper::createdByAtual()
		);
		return self::json(['success' => !empty($res['ok']), 'message' => $res['message'] ?? '']);
	}

	private static function desmatricularEad(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$res = \App\Common\Helpers\LmsMatriculaEadHelper::desmatricular(
			$idAdmin,
			(int)($post['id_aluno'] ?? 0),
			(int)($post['id_curso'] ?? 0)
		);
		return self::json(['success' => !empty($res['ok']), 'message' => $res['message'] ?? '']);
	}

	private static function listarAulas(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idCurso = (int)($post['id_curso'] ?? 0);
		$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
		if (!$curso) {
			return self::json(['success' => false, 'message' => 'Curso não encontrado.']);
		}
		$mod = LmsHelper::garantirModuloPadrao($idCurso, $idAdmin);
		$aulas = [];
		foreach (LmsAula::listByModulo((int)$mod->id, $idAdmin) as $a) {
			$tipo = LmsAula::temColunaInterativa()
				? (string)($a->tipo_conteudo ?? 'video')
				: 'video';
			$cenas = 0;
			if ($tipo === 'interativa' && LmsAulaCena::tabelaExiste()) {
				$cenas = count(LmsAulaCena::listByAula((int)$a->id, $idAdmin));
			}
			$aulas[] = [
				'id' => (int)$a->id,
				'titulo' => $a->titulo,
				'descricao' => $a->descricao,
				'ordem' => (int)$a->ordem,
				'bloqueado' => (int)$a->bloqueado,
				'tipo_conteudo' => $tipo,
				'interativa_status' => LmsAula::temColunaInterativa()
					? (string)($a->interativa_status ?? 'rascunho')
					: null,
				'cenas' => $cenas,
				'videos' => count(LmsVideo::listByAula((int)$a->id, $idAdmin)),
				'materiais' => count(LmsMaterial::listByAula((int)$a->id, $idAdmin)),
				'atividades' => count(LmsAtividade::listByAula((int)$a->id, $idAdmin)),
			];
		}
		return self::json(['success' => true, 'id_modulo' => (int)$mod->id, 'aulas' => $aulas]);
	}

	private static function carregarAula(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idAula = (int)($post['id_aula'] ?? 0);
		$aula = LmsAula::getByIdAdmin($idAula, $idAdmin);
		if (!$aula) {
			return self::json(['success' => false, 'message' => 'Aula não encontrada.']);
		}
		$videos = array_map(static function ($v) {
			return [
				'id' => (int)$v->id,
				'titulo' => $v->titulo,
				'url' => $v->url,
				'provider' => $v->provider,
				'bunny_video_id' => $v->bunny_video_id ?? null,
				'bunny_status' => $v->bunny_status ?? null,
				'bunny_error' => $v->bunny_error ?? null,
				'duracao_min' => (int)$v->duracao_min,
				'ordem' => (int)$v->ordem,
			];
		}, LmsVideo::listByAula($idAula, $idAdmin));

		$materiais = array_map(static function ($m) {
			return [
				'id' => (int)$m->id,
				'label' => $m->label,
				'url' => $m->url,
				'tipo' => $m->tipo,
				'ordem' => (int)$m->ordem,
			];
		}, LmsMaterial::listByAula($idAula, $idAdmin));

		$atividades = [];
		foreach (LmsAtividade::listByAula($idAula, $idAdmin) as $at) {
			$questoes = array_map(static function ($q) {
				$ops = json_decode((string)($q->opcoes ?? '[]'), true);
				return [
					'id' => (int)$q->id,
					'tipo' => $q->tipo,
					'enunciado' => $q->enunciado,
					'opcoes' => is_array($ops) ? $ops : [],
					'resposta_correta' => $q->resposta_correta,
					'ordem' => (int)$q->ordem,
				];
			}, LmsQuestao::listByAtividade((int)$at->id, $idAdmin));
			$atividades[] = [
				'id' => (int)$at->id,
				'titulo' => $at->titulo,
				'descricao' => $at->descricao,
				'duracao_min' => (int)$at->duracao_min,
				'tentativas_max' => (int)$at->tentativas_max,
				'ordem' => (int)$at->ordem,
				'questoes' => $questoes,
			];
		}

		$mod = LmsModulo::getByIdAdmin((int)$aula->id_modulo, $idAdmin);
		$idCurso = $mod ? (int)$mod->id_curso : 0;
		$roleplays = [];
		foreach (LmsRoleplayCenario::listByCurso($idCurso, $idAdmin) as $rp) {
			if ((int)($rp->id_aula ?? 0) !== $idAula) {
				continue;
			}
			$obj = json_decode((string)($rp->objectives ?? '[]'), true);
			$crit = json_decode((string)($rp->criteria ?? '[]'), true);
			$roleplays[] = [
				'id' => (int)$rp->id,
				'titulo' => $rp->titulo,
				'tema' => $rp->tema,
				'cenario' => $rp->cenario,
				'user_role' => $rp->user_role,
				'ai_role' => $rp->ai_role,
				'ai_character_name' => $rp->ai_character_name,
				'difficulty' => $rp->difficulty,
				'min_score' => (int)$rp->min_score,
				'base_prompt' => $rp->base_prompt,
				'initial_personality' => $rp->initial_personality,
				'initial_message' => $rp->initial_message,
				'estimated_minutes' => (int)$rp->estimated_minutes,
				'objectives' => is_array($obj) ? $obj : [],
				'criteria' => is_array($crit) ? $crit : [],
			];
		}

		$bunnySql = LmsVideo::temColunasBunny();
		$bunnyStream = BunnyStreamHelper::pronto($idAdmin);
		$bunnyMotivo = null;
		if (!$bunnySql) {
			$bunnyMotivo = 'Execute database/lms_videos_bunny.sql no phpMyAdmin.';
		} elseif (!$bunnyStream) {
			$bunnyMotivo = PlataformaBunny::get()->streamDiagnostico() ?: 'Bunny Stream incompleto no Master.';
		}

		return self::json([
			'success' => true,
			'bunny_ok' => $bunnyStream && $bunnySql,
			'bunny_motivo' => $bunnyMotivo,
			'aula' => [
				'id' => (int)$aula->id,
				'id_modulo' => (int)$aula->id_modulo,
				'titulo' => $aula->titulo,
				'descricao' => $aula->descricao,
				'ordem' => (int)$aula->ordem,
				'bloqueado' => (int)$aula->bloqueado,
			],
			'videos' => $videos,
			'materiais' => $materiais,
			'atividades' => $atividades,
			'roleplays' => $roleplays,
			'id_curso' => $idCurso,
		]);
	}

	private static function salvarAula(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idCurso = (int)($post['id_curso'] ?? 0);
		$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
		if (!$curso) {
			return self::json(['success' => false, 'message' => 'Curso não encontrado.']);
		}
		$mod = LmsHelper::garantirModuloPadrao($idCurso, $idAdmin);
		$idAula = (int)($post['id_aula'] ?? 0);
		$aula = $idAula > 0 ? LmsAula::getByIdAdmin($idAula, $idAdmin) : new LmsAula();
		if ($idAula > 0 && !$aula) {
			return self::json(['success' => false, 'message' => 'Aula não encontrada.']);
		}
		$aula->id_modulo = (int)$mod->id;
		$aula->id_admin = $idAdmin;
		$aula->titulo = trim((string)($post['titulo'] ?? 'Nova aula'));
		if ($aula->titulo === '') {
			$aula->titulo = 'Nova aula';
		}
		$aula->descricao = trim((string)($post['descricao'] ?? ''));
		$aula->ordem = (int)($post['ordem'] ?? 0);
		$aula->bloqueado = !empty($post['bloqueado']) ? 1 : 0;
		$id = $aula->salvar();
		return self::json(['success' => true, 'message' => 'Aula salva.', 'id_aula' => $id]);
	}

	private static function excluirAula(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idAula = (int)($post['id_aula'] ?? 0);
		$aula = LmsAula::getByIdAdmin($idAula, $idAdmin);
		if (!$aula) {
			return self::json(['success' => false, 'message' => 'Aula não encontrada.']);
		}
		foreach (LmsVideo::listByAula($idAula, $idAdmin) as $v) {
			$v->excluir();
		}
		foreach (LmsMaterial::listByAula($idAula, $idAdmin) as $m) {
			$m->excluir();
		}
		foreach (LmsAtividade::listByAula($idAula, $idAdmin) as $at) {
			foreach (LmsQuestao::listByAtividade((int)$at->id, $idAdmin) as $q) {
				$q->excluir();
			}
			$at->excluir();
		}
		$aula->excluir();
		return self::json(['success' => true, 'message' => 'Aula excluída.']);
	}

	private static function salvarVideo(array $post): string {
		return self::json([
			'success' => false,
			'message' => 'YouTube/URL externa não é mais aceito. Envie o vídeo pelo Bunny.',
		]);
	}

	private static function excluirVideo(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$v = LmsVideo::getByIdAdmin($id, $idAdmin);
		if (!$v) {
			return self::json(['success' => false, 'message' => 'Vídeo não encontrado.']);
		}
		if (($v->provider ?? '') === 'bunny' && !empty($v->bunny_video_id)) {
			BunnyStreamHelper::excluirVideo($idAdmin, (string)$v->bunny_video_id);
		}
		$v->excluir();
		return self::json(['success' => true, 'message' => 'Vídeo excluído.']);
	}

	private static function bunnyCriarVideo(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		if (!LmsVideo::temColunasBunny()) {
			return self::json(['success' => false, 'message' => 'Execute database/lms_videos_bunny.sql']);
		}
		if (!BunnyStreamHelper::pronto($idAdmin)) {
			$motivo = PlataformaBunny::get()->streamDiagnostico() ?: 'Bunny Stream incompleto.';
			return self::json([
				'success' => false,
				'message' => 'Bunny Stream não configurado no Master. '.$motivo,
			]);
		}
		$idAula = (int)($post['id_aula'] ?? 0);
		$aula = LmsAula::getByIdAdmin($idAula, $idAdmin);
		if (!$aula) {
			return self::json(['success' => false, 'message' => 'Aula não encontrada.']);
		}
		$titulo = trim((string)($post['titulo'] ?? '')) ?: ((string)$aula->titulo.' — vídeo');
		$created = BunnyStreamHelper::criarVideo($idAdmin, $titulo);
		if (empty($created['ok'])) {
			return self::json(['success' => false, 'message' => $created['message'] ?? 'Falha ao criar no Bunny.']);
		}
		$guid = (string)$created['videoId'];
		$auth = BunnyStreamHelper::assinaturaUpload($idAdmin, $guid);
		if (empty($auth['ok'])) {
			return self::json(['success' => false, 'message' => $auth['message'] ?? 'Falha na assinatura de upload.']);
		}
		$v = new LmsVideo();
		$v->id_aula = $idAula;
		$v->id_admin = $idAdmin;
		$v->titulo = $titulo;
		$v->url = 'bunny:'.$guid;
		$v->provider = 'bunny';
		$v->bunny_video_id = $guid;
		$v->bunny_status = 'uploading';
		$v->bunny_error = null;
		$v->duracao_min = 0;
		$v->ordem = (int)($post['ordem'] ?? 0);
		$newId = $v->salvar();
		return self::json([
			'success' => true,
			'id' => $newId,
			'bunny_video_id' => $guid,
			'upload' => [
				'putUrl' => $auth['putUrl'],
				'accessKey' => $auth['accessKey'],
				'libraryId' => $auth['libraryId'],
				'videoId' => $auth['videoId'],
				'expires' => $auth['expires'],
				'signature' => $auth['signature'],
			],
		]);
	}

	private static function bunnyUploadAuth(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$v = LmsVideo::getByIdAdmin($id, $idAdmin);
		if (!$v || ($v->provider ?? '') !== 'bunny' || empty($v->bunny_video_id)) {
			return self::json(['success' => false, 'message' => 'Vídeo Bunny não encontrado.']);
		}
		$auth = BunnyStreamHelper::assinaturaUpload($idAdmin, (string)$v->bunny_video_id);
		if (empty($auth['ok'])) {
			return self::json(['success' => false, 'message' => $auth['message'] ?? 'Falha']);
		}
		return self::json([
			'success' => true,
			'upload' => [
				'putUrl' => $auth['putUrl'],
				'accessKey' => $auth['accessKey'],
				'libraryId' => $auth['libraryId'],
				'videoId' => $auth['videoId'],
				'expires' => $auth['expires'],
				'signature' => $auth['signature'],
			],
		]);
	}

	private static function bunnyFinalize(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$v = LmsVideo::getByIdAdmin($id, $idAdmin);
		if (!$v || ($v->provider ?? '') !== 'bunny') {
			return self::json(['success' => false, 'message' => 'Vídeo não encontrado.']);
		}
		$v->bunny_status = 'processing';
		$v->bunny_error = null;
		$v->salvar();
		return self::json(['success' => true, 'bunny_status' => 'processing']);
	}

	private static function bunnyStatus(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$v = LmsVideo::getByIdAdmin($id, $idAdmin);
		if (!$v || ($v->provider ?? '') !== 'bunny' || empty($v->bunny_video_id)) {
			return self::json(['success' => false, 'message' => 'Vídeo não encontrado.']);
		}
		$st = BunnyStreamHelper::statusVideo($idAdmin, (string)$v->bunny_video_id);
		if (empty($st['ok'])) {
			return self::json(['success' => false, 'message' => $st['message'] ?? 'Falha']);
		}
		$v->bunny_status = $st['status'];
		if (($st['status'] ?? '') === 'ready') {
			$v->duracao_min = (int)($st['durationMinutes'] ?? $v->duracao_min);
			$v->bunny_error = null;
		}
		if (($st['status'] ?? '') === 'error') {
			$v->bunny_error = 'Falha no processamento Bunny (código '.((int)($st['bunnyCode'] ?? 0)).').';
		}
		$v->salvar();
		return self::json([
			'success' => true,
			'bunny_status' => $v->bunny_status,
			'duracao_min' => (int)$v->duracao_min,
			'encodeProgress' => (int)($st['encodeProgress'] ?? 0),
			'bunny_error' => $v->bunny_error,
		]);
	}

	private static function salvarMaterial(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idAula = (int)($post['id_aula'] ?? 0);
		$aula = LmsAula::getByIdAdmin($idAula, $idAdmin);
		if (!$aula) {
			return self::json(['success' => false, 'message' => 'Aula não encontrada.']);
		}
		$url = trim((string)($post['url'] ?? ''));
		$label = trim((string)($post['label'] ?? ''));
		if ($url === '' || $label === '') {
			return self::json(['success' => false, 'message' => 'Label e URL obrigatórios.']);
		}
		$id = (int)($post['id'] ?? 0);
		$m = $id > 0 ? LmsMaterial::getByIdAdmin($id, $idAdmin) : new LmsMaterial();
		if ($id > 0 && !$m) {
			return self::json(['success' => false, 'message' => 'Material não encontrado.']);
		}
		$tipo = (string)($post['tipo'] ?? 'link');
		$m->id_aula = $idAula;
		$m->id_admin = $idAdmin;
		$m->label = $label;
		$m->url = $url;
		$m->tipo = in_array($tipo, ['pdf', 'link', 'file'], true) ? $tipo : 'link';
		$m->ordem = (int)($post['ordem'] ?? 0);
		$newId = $m->salvar();
		return self::json(['success' => true, 'message' => 'Material salvo.', 'id' => $newId]);
	}

	private static function excluirMaterial(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$m = LmsMaterial::getByIdAdmin($id, $idAdmin);
		if (!$m) {
			return self::json(['success' => false, 'message' => 'Material não encontrado.']);
		}
		$m->excluir();
		return self::json(['success' => true, 'message' => 'Material excluído.']);
	}

	private static function salvarAtividade(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idCurso = (int)($post['id_curso'] ?? 0);
		$idAula = (int)($post['id_aula'] ?? 0);
		$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
		if (!$curso) {
			return self::json(['success' => false, 'message' => 'Curso não encontrado.']);
		}
		if ($idAula > 0 && !LmsAula::getByIdAdmin($idAula, $idAdmin)) {
			return self::json(['success' => false, 'message' => 'Aula não encontrada.']);
		}
		$id = (int)($post['id'] ?? 0);
		$at = $id > 0 ? LmsAtividade::getByIdAdmin($id, $idAdmin) : new LmsAtividade();
		if ($id > 0 && !$at) {
			return self::json(['success' => false, 'message' => 'Atividade não encontrada.']);
		}
		$at->id_curso = $idCurso;
		$at->id_aula = $idAula > 0 ? $idAula : null;
		$at->id_admin = $idAdmin;
		$at->titulo = trim((string)($post['titulo'] ?? 'Atividade'));
		$at->descricao = trim((string)($post['descricao'] ?? ''));
		$at->duracao_min = (int)($post['duracao_min'] ?? 30);
		$at->tentativas_max = max(1, min(10, (int)($post['tentativas_max'] ?? 3)));
		$at->ordem = (int)($post['ordem'] ?? 0);
		$newId = $at->salvar();
		return self::json(['success' => true, 'message' => 'Atividade salva.', 'id' => $newId]);
	}

	private static function excluirAtividade(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$at = LmsAtividade::getByIdAdmin($id, $idAdmin);
		if (!$at) {
			return self::json(['success' => false, 'message' => 'Atividade não encontrada.']);
		}
		foreach (LmsQuestao::listByAtividade($id, $idAdmin) as $q) {
			$q->excluir();
		}
		$at->excluir();
		return self::json(['success' => true, 'message' => 'Atividade excluída.']);
	}

	private static function salvarQuestao(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idAtividade = (int)($post['id_atividade'] ?? 0);
		$at = LmsAtividade::getByIdAdmin($idAtividade, $idAdmin);
		if (!$at) {
			return self::json(['success' => false, 'message' => 'Atividade não encontrada.']);
		}
		$id = (int)($post['id'] ?? 0);
		$q = $id > 0 ? LmsQuestao::getByIdAdmin($id, $idAdmin) : new LmsQuestao();
		if ($id > 0 && !$q) {
			return self::json(['success' => false, 'message' => 'Questão não encontrada.']);
		}
		$tipo = (string)($post['tipo'] ?? 'multiple');
		$tipo = in_array($tipo, ['multiple', 'boolean', 'essay'], true) ? $tipo : 'multiple';
		$opcoesRaw = $post['opcoes'] ?? '[]';
		if (is_string($opcoesRaw)) {
			$decoded = json_decode($opcoesRaw, true);
			$opcoes = is_array($decoded) ? $decoded : [];
		} else {
			$opcoes = is_array($opcoesRaw) ? $opcoesRaw : [];
		}
		$resposta = trim((string)($post['resposta_correta'] ?? ''));
		if ($tipo === 'boolean') {
			$opcoes = [
				['id' => 'true', 'label' => 'Verdadeiro'],
				['id' => 'false', 'label' => 'Falso'],
			];
			$t = strtolower($resposta);
			$resposta = in_array($t, ['0', 'false', 'f', 'falso', 'nao', 'não', 'n'], true) ? 'false' : 'true';
		} elseif ($tipo === 'essay') {
			$opcoes = [];
			// Gabarito opcional em resposta_correta (contas objetivas ou referência para IA)
		}
		$q->id_atividade = $idAtividade;
		$q->id_admin = $idAdmin;
		$q->tipo = $tipo;
		$q->enunciado = trim((string)($post['enunciado'] ?? ''));
		$q->opcoes = json_encode($opcoes, JSON_UNESCAPED_UNICODE);
		$q->resposta_correta = $resposta;
		$q->ordem = (int)($post['ordem'] ?? 0);
		if ($q->enunciado === '') {
			return self::json(['success' => false, 'message' => 'Enunciado obrigatório.']);
		}
		$newId = $q->salvar();
		return self::json(['success' => true, 'message' => 'Questão salva.', 'id' => $newId]);
	}

	private static function excluirQuestao(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$q = LmsQuestao::getByIdAdmin($id, $idAdmin);
		if (!$q) {
			return self::json(['success' => false, 'message' => 'Questão não encontrada.']);
		}
		$q->excluir();
		return self::json(['success' => true, 'message' => 'Questão excluída.']);
	}

	private static function salvarRoleplay(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idCurso = (int)($post['id_curso'] ?? 0);
		$idAula = (int)($post['id_aula'] ?? 0);
		$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
		if (!$curso) {
			return self::json(['success' => false, 'message' => 'Curso não encontrado.']);
		}
		$id = (int)($post['id'] ?? 0);
		$rp = $id > 0 ? LmsRoleplayCenario::getByIdAdmin($id, $idAdmin) : new LmsRoleplayCenario();
		if ($id > 0 && !$rp) {
			return self::json(['success' => false, 'message' => 'Cenário não encontrado.']);
		}
		$objs = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($post['objectives_text'] ?? '')) ?: [])));
		$rp->id_curso = $idCurso;
		$rp->id_aula = $idAula > 0 ? $idAula : null;
		$rp->id_admin = $idAdmin;
		$rp->titulo = trim((string)($post['titulo'] ?? 'Role play'));
		$rp->tema = trim((string)($post['tema'] ?? ''));
		$rp->cenario = trim((string)($post['cenario'] ?? ''));
		$rp->user_role = trim((string)($post['user_role'] ?? ''));
		$rp->ai_role = trim((string)($post['ai_role'] ?? ''));
		$rp->ai_character_name = trim((string)($post['ai_character_name'] ?? ''));
		$rp->difficulty = (string)($post['difficulty'] ?? 'medium');
		$rp->min_score = (int)($post['min_score'] ?? 70);
		$rp->base_prompt = trim((string)($post['base_prompt'] ?? ''));
		$rp->initial_personality = trim((string)($post['initial_personality'] ?? ''));
		$rp->initial_message = trim((string)($post['initial_message'] ?? ''));
		$rp->estimated_minutes = (int)($post['estimated_minutes'] ?? 15);
		$rp->objectives = json_encode($objs, JSON_UNESCAPED_UNICODE);
		$rp->criteria = '[]';
		$newId = $rp->salvar();
		return self::json(['success' => true, 'message' => 'Role play salvo.', 'id' => $newId]);
	}

	private static function excluirRoleplay(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$id = (int)($post['id'] ?? 0);
		$rp = LmsRoleplayCenario::getByIdAdmin($id, $idAdmin);
		if (!$rp) {
			return self::json(['success' => false, 'message' => 'Cenário não encontrado.']);
		}
		$rp->excluir();
		return self::json(['success' => true, 'message' => 'Role play excluído.']);
	}

	/** One-time token → ASCEND_URL/editor/auth?token=PLAIN */
	private static function abrirEditor(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$idUsuario = TenantHelper::getUsuarioId();
		if ($idAdmin <= 0 || $idUsuario <= 0) {
			return self::json(['success' => false, 'message' => 'Sessão inválida.']);
		}
		$idCurso = !empty($post['id_curso']) ? (int)$post['id_curso'] : null;
		if ($idCurso !== null && $idCurso > 0) {
			$curso = LmsCurso::getByIdAdmin($idCurso, $idAdmin);
			if (!$curso) {
				return self::json(['success' => false, 'message' => 'Curso não encontrado.']);
			}
		} else {
			$idCurso = null;
		}

		$idAula = !empty($post['id_aula']) ? (int)$post['id_aula'] : 0;
		if ($idAula > 0) {
			$aula = LmsAula::getByIdAdmin($idAula, $idAdmin);
			if (!$aula instanceof LmsAula) {
				return self::json(['success' => false, 'message' => 'Aula não encontrada.']);
			}
			if ($idCurso !== null && $idCurso > 0) {
				$mod = LmsModulo::getByIdAdmin((int)$aula->id_modulo, $idAdmin);
				if (!$mod || (int)$mod->id_curso !== $idCurso) {
					return self::json(['success' => false, 'message' => 'Aula não pertence a este curso.']);
				}
			}
		}

		try {
			$plain = LmsEditorToken::criar($idAdmin, $idUsuario, $idCurso);
		} catch (\Throwable $e) {
			return self::json([
				'success' => false,
				'message' => 'Execute o SQL database/lms_aulas_interativas.sql no phpMyAdmin.',
			]);
		}

		$base = rtrim((string)(
			Environment::get('ASCEND_URL')
			?: getenv('ASCEND_URL')
			?: 'http://localhost:8080'
		), '/');

		$url = $base.'/editor/auth?token='.rawurlencode($plain);
		if ($idAula > 0) {
			$url .= '&aulaId='.(int)$idAula;
		}

		return self::json([
			'success' => true,
			'url' => $url,
			'id_aula' => $idAula > 0 ? $idAula : null,
		]);
	}
}
