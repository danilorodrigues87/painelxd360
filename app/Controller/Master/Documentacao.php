<?php

namespace App\Controller\Master;

use App\Utils\View;
use App\Model\Entity\PlatformHelpCategoria;
use App\Model\Entity\PlatformHelpArtigo;
use App\Common\Help\PlatformHelpSeed;

class Documentacao extends Page {

	private static function slugify(string $t): string {
		$t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
		$t = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$t) ?? '');
		return trim($t, '-') ?: 'item-'.time();
	}

	public static function index($request) {
		if (!PlatformHelpCategoria::tabelasExistem()) {
			$content = View::render('master/modules/documentacao/sql', []);
			return parent::getPanel('Documentação — Master', $content, 'documentacao');
		}

		$catsHtml = '';
		foreach (PlatformHelpCategoria::listAll() as $c) {
			$arts = PlatformHelpArtigo::listByCategoria((int)$c->id);
			$artsHtml = '';
			foreach ($arts as $a) {
				$pub = (int)$a->publicado === 1 ? 'publicado' : 'rascunho';
				$artsHtml .= '<tr>'
					.'<td>'.(int)$a->id.'</td>'
					.'<td>'.htmlspecialchars((string)$a->titulo, ENT_QUOTES, 'UTF-8').'</td>'
					.'<td><code>'.htmlspecialchars((string)$a->slug, ENT_QUOTES, 'UTF-8').'</code></td>'
					.'<td>'.(int)$a->ordem.'</td>'
					.'<td><span class="badge bg-'.($pub === 'publicado' ? 'success' : 'secondary').'">'.$pub.'</span></td>'
					.'<td class="text-nowrap">'
					.'<button type="button" class="btn btn-sm btn-outline-primary btn-edit-art" data-id="'.(int)$a->id.'">Editar</button> '
					.'<button type="button" class="btn btn-sm btn-outline-danger btn-del-art" data-id="'.(int)$a->id.'">Excluir</button>'
					.'</td></tr>';
			}
			if ($artsHtml === '') {
				$artsHtml = '<tr><td colspan="6" class="text-muted small">Nenhum artigo.</td></tr>';
			}
			$catsHtml .= '<div class="card shadow mb-3" data-cat="'.(int)$c->id.'">'
				.'<div class="card-header d-flex flex-wrap gap-2 align-items-center">'
				.'<strong>'.htmlspecialchars((string)$c->titulo, ENT_QUOTES, 'UTF-8').'</strong>'
				.' <span class="badge bg-'.((int)$c->ativo ? 'success' : 'secondary').'">'.((int)$c->ativo ? 'ativa' : 'inativa').'</span>'
				.' <code class="small">'.htmlspecialchars((string)$c->slug, ENT_QUOTES, 'UTF-8').'</code>'
				.' <span class="text-muted small">ordem '.(int)$c->ordem.'</span>'
				.'<div class="ms-auto">'
				.'<button type="button" class="btn btn-sm btn-outline-secondary btn-edit-cat" data-id="'.(int)$c->id.'" data-titulo="'.htmlspecialchars((string)$c->titulo, ENT_QUOTES, 'UTF-8').'" data-slug="'.htmlspecialchars((string)$c->slug, ENT_QUOTES, 'UTF-8').'" data-ordem="'.(int)$c->ordem.'" data-ativo="'.(int)$c->ativo.'">Editar cat.</button> '
				.'<button type="button" class="btn btn-sm btn-primary btn-new-art" data-cat="'.(int)$c->id.'">+ Artigo</button>'
				.'</div></div>'
				.'<div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0">'
				.'<thead><tr><th>ID</th><th>Título</th><th>Slug</th><th>Ord.</th><th>Status</th><th></th></tr></thead>'
				.'<tbody>'.$artsHtml.'</tbody></table></div></div></div>';
		}

		$content = View::render('master/modules/documentacao/index', [
			'categorias_html' => $catsHtml ?: '<p class="text-muted">Nenhuma categoria.</p>',
		]);
		return parent::getPanel('Documentação — Master', $content, 'documentacao');
	}

	public static function salvar($request) {
		if (!PlatformHelpCategoria::tabelasExistem()) {
			return json_encode(['success' => false, 'message' => 'Execute database/platform_help.sql']);
		}
		$post = $request->getPostVars();
		$acao = (string)($post['acao'] ?? '');

		if ($acao === 'salvar_categoria') {
			$id = (int)($post['id'] ?? 0);
			$titulo = trim((string)($post['titulo'] ?? ''));
			if ($titulo === '') {
				return json_encode(['success' => false, 'message' => 'Título obrigatório.']);
			}
			$slug = trim((string)($post['slug'] ?? ''));
			if ($slug === '') {
				$slug = self::slugify($titulo);
			}
			$ob = $id > 0 ? PlatformHelpCategoria::getById($id) : new PlatformHelpCategoria();
			if ($id > 0 && !$ob) {
				return json_encode(['success' => false, 'message' => 'Categoria não encontrada.']);
			}
			$ob->titulo = $titulo;
			$ob->slug = $slug;
			$ob->ordem = (int)($post['ordem'] ?? 0);
			$ob->ativo = !empty($post['ativo']) ? 1 : 0;
			$ob->salvar();
			return json_encode(['success' => true, 'message' => 'Categoria salva.', 'id' => (int)$ob->id]);
		}

		if ($acao === 'excluir_categoria') {
			$id = (int)($post['id'] ?? 0);
			$ob = PlatformHelpCategoria::getById($id);
			if (!$ob) {
				return json_encode(['success' => false, 'message' => 'Não encontrada.']);
			}
			$ob->excluir();
			return json_encode(['success' => true, 'message' => 'Categoria excluída.']);
		}

		if ($acao === 'get_artigo') {
			$ob = PlatformHelpArtigo::getById((int)($post['id'] ?? 0));
			if (!$ob) {
				return json_encode(['success' => false, 'message' => 'Artigo não encontrado.']);
			}
			return json_encode([
				'success' => true,
				'artigo' => [
					'id' => (int)$ob->id,
					'id_categoria' => (int)$ob->id_categoria,
					'titulo' => (string)$ob->titulo,
					'slug' => (string)$ob->slug,
					'resumo' => (string)($ob->resumo ?? ''),
					'corpo' => (string)($ob->corpo ?? ''),
					'video_url' => (string)($ob->video_url ?? ''),
					'video_titulo' => (string)($ob->video_titulo ?? ''),
					'ordem' => (int)$ob->ordem,
					'publicado' => (int)$ob->publicado,
				],
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		if ($acao === 'salvar_artigo') {
			$id = (int)($post['id'] ?? 0);
			$idCat = (int)($post['id_categoria'] ?? 0);
			$titulo = trim((string)($post['titulo'] ?? ''));
			if ($titulo === '' || $idCat <= 0) {
				return json_encode(['success' => false, 'message' => 'Título e categoria obrigatórios.']);
			}
			$slug = trim((string)($post['slug'] ?? ''));
			if ($slug === '') {
				$slug = self::slugify($titulo);
			}
			$ob = $id > 0 ? PlatformHelpArtigo::getById($id) : new PlatformHelpArtigo();
			if ($id > 0 && !$ob) {
				return json_encode(['success' => false, 'message' => 'Artigo não encontrado.']);
			}
			$ob->id_categoria = $idCat;
			$ob->titulo = $titulo;
			$ob->slug = $slug;
			$ob->resumo = trim((string)($post['resumo'] ?? ''));
			$ob->corpo = (string)($post['corpo'] ?? '');
			$ob->video_url = trim((string)($post['video_url'] ?? ''));
			$ob->video_titulo = trim((string)($post['video_titulo'] ?? ''));
			$ob->ordem = (int)($post['ordem'] ?? 0);
			$ob->publicado = !empty($post['publicado']) ? 1 : 0;
			$ob->salvar();
			return json_encode(['success' => true, 'message' => 'Artigo salvo.', 'id' => (int)$ob->id]);
		}

		if ($acao === 'excluir_artigo') {
			$ob = PlatformHelpArtigo::getById((int)($post['id'] ?? 0));
			if (!$ob) {
				return json_encode(['success' => false, 'message' => 'Não encontrado.']);
			}
			$ob->excluir();
			return json_encode(['success' => true, 'message' => 'Artigo excluído.']);
		}

		if ($acao === 'seed_tutoriais') {
			try {
				$r = PlatformHelpSeed::aplicar();
				return json_encode([
					'success' => true,
					'message' => 'Tutoriais aplicados: '.$r['created'].' criado(s), '.$r['updated'].' atualizado(s), '.$r['cats'].' categoria(s). Vídeos vazios preservados se já preenchidos.',
					'resultado' => $r,
				], JSON_UNESCAPED_UNICODE);
			} catch (\Throwable $e) {
				return json_encode(['success' => false, 'message' => $e->getMessage()]);
			}
		}

		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}
}
