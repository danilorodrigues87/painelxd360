<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\LmsHelper;
use App\Common\Helpers\BunnyStreamHelper;
use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsModulo;
use App\Model\Entity\LmsAula;
use App\Model\Entity\LmsVideo;
use App\Model\Entity\LmsMaterial;
use App\Model\Entity\LmsVitrineAssinatura;
use App\Model\Entity\ClientesAssinantes;

class EadVitrine extends Page {

	private static function json(array $data): string {
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public static function index($request) {
		$idAdmin = TenantHelper::getIdAdmin();
		if (!\App\Common\Helpers\LmsVitrineHelper::deveExibirParaEscola($idAdmin)) {
			$request->getRouter()->redirect('/painel/ead');
			return '';
		}
		$content = View::render('admin/modules/ead/vitrine', []);
		return parent::getPanel('Vitrine de cursos', $content, 'portal_ead', $request);
	}

	public static function getInfo($request) {
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';
		if (!LmsVitrineAssinatura::tabelaExiste() || !LmsCurso::temColunaVitrine()) {
			return self::json([
				'success' => false,
				'sql_ok' => false,
				'message' => 'Execute database/lms_vitrine.sql no phpMyAdmin.',
			]);
		}
		if ($acao === 'listar') {
			return self::listar();
		}
		if ($acao === 'amostra' || $acao === 'detalhe') {
			return self::amostra((int)($post['id_curso'] ?? 0));
		}
		if ($acao === 'assinar') {
			return self::assinar((int)($post['id_curso'] ?? 0));
		}
		if ($acao === 'cancelar') {
			return self::cancelar((int)($post['id_curso'] ?? 0));
		}
		return self::json(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function contagemCurso(LmsCurso $c): array {
		$idOwner = (int)$c->id_admin;
		$modulos = 0;
		$aulas = 0;
		foreach (LmsModulo::listByCurso((int)$c->id, $idOwner) as $mod) {
			$modulos++;
			$aulas += count(LmsAula::listByModulo((int)$mod->id, $idOwner));
		}
		return ['modulos' => $modulos, 'aulas' => $aulas];
	}

	private static function listar(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$assinadas = [];
		foreach (LmsVitrineAssinatura::listAtivasEscola($idAdmin) as $a) {
			$assinadas[(int)$a->id_curso] = $a;
		}

		$stmt = LmsCurso::get(
			'vitrine_ativo = 1 AND publicado = 1 AND id_admin <> '.(int)$idAdmin,
			'titulo ASC'
		);
		$itens = [];
		while ($c = $stmt->fetchObject(LmsCurso::class)) {
			$escola = ClientesAssinantes::getEscolaById((int)$c->id_admin);
			$ass = $assinadas[(int)$c->id] ?? null;
			$cnt = self::contagemCurso($c);
			$itens[] = [
				'id_curso' => (int)$c->id,
				'titulo' => $c->nomeExibicao(),
				'descricao' => (string)($c->vitrine_descricao ?: $c->short_description),
				'preco_mensal' => (float)$c->vitrine_preco_mensal,
				'escola' => $escola ? (string)($escola->nome ?? 'Escola') : 'Escola',
				'assinado' => $ass !== null,
				'inicio' => $ass ? $ass->inicio : null,
				'cover_url' => (string)($c->cover_url ?? ''),
				'banner_url' => (string)($c->banner_url ?? ''),
				'level' => (string)($c->level ?? ''),
				'instructor_name' => (string)($c->instructor_name ?? ''),
				'carga_h' => $c->carga_h !== null && $c->carga_h !== '' ? (int)$c->carga_h : null,
				'modulos' => $cnt['modulos'],
				'aulas' => $cnt['aulas'],
			];
		}

		$minhas = [];
		foreach ($assinadas as $a) {
			$c = LmsCurso::getById((int)$a->id_curso);
			if (!$c) {
				continue;
			}
			$minhas[] = [
				'id_curso' => (int)$c->id,
				'titulo' => $c->nomeExibicao(),
				'preco_mensal' => (float)$c->vitrine_preco_mensal,
				'inicio' => $a->inicio,
				'cover_url' => (string)($c->cover_url ?? ''),
				'escola' => '',
			];
		}

		return self::json([
			'success' => true,
			'sql_ok' => true,
			'itens' => $itens,
			'minhas' => $minhas,
		]);
	}

	/**
	 * Amostra pré-assinatura: textos, tópicos, 1º vídeo, PDFs; sem atividades/roleplay.
	 */
	private static function amostra(int $idCurso): string {
		$idAdmin = TenantHelper::getIdAdmin();
		if ($idCurso <= 0) {
			return self::json(['success' => false, 'message' => 'Curso inválido.']);
		}
		$curso = LmsCurso::getById($idCurso);
		if (!$curso) {
			return self::json(['success' => false, 'message' => 'Curso não encontrado.']);
		}
		if ((int)$curso->id_admin === $idAdmin) {
			return self::json(['success' => false, 'message' => 'Este curso já é da sua escola.']);
		}
		$ass = LmsVitrineAssinatura::ativaParaEscolaCurso($idAdmin, $idCurso);
		$naVitrine = (int)$curso->vitrine_ativo === 1 && (int)$curso->publicado === 1;
		if (!$naVitrine && !$ass) {
			return self::json(['success' => false, 'message' => 'Curso não disponível na vitrine.']);
		}

		$idOwner = (int)$curso->id_admin;
		$escola = ClientesAssinantes::getEscolaById($idOwner);

		$modulos = [];
		$pdfs = [];
		$primeiroVideo = null;

		foreach (LmsModulo::listByCurso((int)$curso->id, $idOwner) as $mod) {
			$aulasOut = [];
			foreach (LmsAula::listByModulo((int)$mod->id, $idOwner) as $aula) {
				$aulasOut[] = [
					'titulo' => (string)$aula->titulo,
					'descricao' => (string)($aula->descricao ?? ''),
					'ordem' => (int)$aula->ordem,
				];
				foreach (LmsMaterial::listByAula((int)$aula->id, $idOwner) as $mat) {
					if (($mat->tipo ?? '') !== 'pdf') {
						continue;
					}
					$url = trim((string)($mat->url ?? ''));
					if ($url === '') {
						continue;
					}
					$pdfs[] = [
						'label' => (string)($mat->label ?: 'Material PDF'),
						'url' => $url,
						'aula' => (string)$aula->titulo,
					];
				}
				if ($primeiroVideo === null) {
					foreach (LmsVideo::listByAula((int)$aula->id, $idOwner) as $v) {
						$provider = (string)($v->provider ?: 'youtube');
						if ($provider === 'bunny') {
							$status = (string)($v->bunny_status ?? '');
							if ($status !== 'ready' && !empty($v->bunny_video_id)) {
								$status = BunnyStreamHelper::sincronizarStatusVideo($v, $idOwner);
							}
							if ($status !== 'ready' || empty($v->bunny_video_id)) {
								continue;
							}
							$play = BunnyStreamHelper::urlPlayback($idOwner, (string)$v->bunny_video_id, 3600);
							if (empty($play['ok']) || empty($play['playbackUrl'])) {
								continue;
							}
							$primeiroVideo = [
								'titulo' => (string)($v->titulo ?: 'Vídeo de amostra'),
								'provider' => 'bunny',
								'playbackUrl' => (string)$play['playbackUrl'],
								'embedUrl' => null,
								'duracao_min' => (int)$v->duracao_min,
							];
							break;
						}
						$url = LmsHelper::normalizeVideoUrl((string)$v->url, $provider);
						if ($url === '') {
							continue;
						}
						$primeiroVideo = [
							'titulo' => (string)($v->titulo ?: 'Vídeo de amostra'),
							'provider' => $provider === 'youtube' ? 'youtube' : $provider,
							'playbackUrl' => null,
							'embedUrl' => $url,
							'duracao_min' => (int)$v->duracao_min,
						];
						break;
					}
				}
			}
			$modulos[] = [
				'titulo' => (string)$mod->titulo,
				'ordem' => (int)$mod->ordem,
				'aulas' => $aulasOut,
			];
		}

		return self::json([
			'success' => true,
			'curso' => [
				'id_curso' => (int)$curso->id,
				'titulo' => $curso->nomeExibicao(),
				'descricao' => (string)($curso->vitrine_descricao ?: $curso->short_description),
				'description' => (string)($curso->vitrine_descricao ?: $curso->short_description),
				'objectives' => (string)($curso->objectives ?? ''),
				'cover_url' => (string)($curso->cover_url ?? ''),
				'level' => (string)($curso->level ?? ''),
				'instructor_name' => (string)($curso->instructor_name ?? ''),
				'instructor_title' => (string)($curso->instructor_title ?? ''),
				'carga_h' => $curso->carga_h !== null && $curso->carga_h !== '' ? (int)$curso->carga_h : null,
				'preco_mensal' => (float)$curso->vitrine_preco_mensal,
				'assinado' => $ass !== null,
			],
			'escola' => [
				'nome' => $escola ? (string)($escola->nome ?? 'Escola') : 'Escola',
				'email' => $escola ? trim((string)($escola->email ?? '')) : '',
				'telefone' => $escola ? trim((string)($escola->telefone ?? '')) : '',
			],
			'modulos' => $modulos,
			'video_amostra' => $primeiroVideo,
			'pdfs' => $pdfs,
			'aviso' => 'Amostra limitada. Para ver o material completo, fale com a escola dona e peça um acesso de demonstração.',
		]);
	}

	private static function assinar(int $idCurso): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$curso = LmsCurso::getById($idCurso);
		if (!$curso || (int)$curso->vitrine_ativo !== 1 || (int)$curso->publicado !== 1) {
			return self::json(['success' => false, 'message' => 'Curso não disponível na vitrine.']);
		}
		if ((int)$curso->id_admin === $idAdmin) {
			return self::json(['success' => false, 'message' => 'Este curso já é da sua escola.']);
		}

		$exist = LmsVitrineAssinatura::get(
			'id_escola_assinante = '.$idAdmin.' AND id_curso = '.$idCurso
		)->fetchObject(LmsVitrineAssinatura::class);

		if ($exist instanceof LmsVitrineAssinatura) {
			$exist->status = 'ativa';
			$exist->cancelada_em = null;
			if (empty($exist->inicio)) {
				$exist->inicio = date('Y-m-d');
			}
			$exist->salvar();
		} else {
			$a = new LmsVitrineAssinatura();
			$a->id_escola_assinante = $idAdmin;
			$a->id_escola_criadora = (int)$curso->id_admin;
			$a->id_curso = $idCurso;
			$a->status = 'ativa';
			$a->inicio = date('Y-m-d');
			$a->salvar();
		}

		return self::json(['success' => true, 'message' => ((float)$curso->vitrine_preco_mensal > 0)
			? 'Licença ativada. O valor entra na próxima fatura SaaS.'
			: 'Licença gratuita ativada. Agora você pode matricular seus alunos neste curso.']);
	}

	private static function cancelar(int $idCurso): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$exist = LmsVitrineAssinatura::ativaParaEscolaCurso($idAdmin, $idCurso);
		if (!$exist) {
			return self::json(['success' => false, 'message' => 'Assinatura não encontrada.']);
		}
		$exist->status = 'cancelada';
		$exist->cancelada_em = date('Y-m-d');
		$exist->salvar();
		return self::json(['success' => true, 'message' => 'Licença cancelada (não entra no próximo ciclo).']);
	}
}
