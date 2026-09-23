<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Model\Entity\PlatformHelpCategoria;
use App\Model\Entity\PlatformHelpArtigo;

class Ajuda extends Page {

	public static function index($request) {
		$content = self::renderLista(true);
		return parent::getPanel('Ajuda', $content, 'Ajuda', $request);
	}

	public static function artigo($request, $slug) {
		$content = self::renderArtigo((string)$slug, true);
		return parent::getPanel('Ajuda', $content, 'Ajuda', $request);
	}

	/** Lista pública (sem layout do painel). */
	public static function indexPublico($request) {
		$inner = self::renderLista(false);
		return self::wrapPublico('Central de Ajuda — CTI Educacional', $inner);
	}

	public static function artigoPublico($request, $slug) {
		$inner = self::renderArtigo((string)$slug, false);
		return self::wrapPublico('Ajuda — CTI Educacional', $inner);
	}

	private static function wrapPublico(string $title, string $inner): string {
		$content = View::render('public/ajuda-card', [
			'logo_url' => \App\Common\Helpers\BrandingHelper::urlLogoCti(),
			'content' => $inner,
			'URL' => rtrim((string)URL, '/'),
		]);
		return View::render('login/page', [
			'title' => $title,
			'content' => $content,
			'favicon_url' => \App\Common\Helpers\BrandingHelper::urlFaviconCti(),
		]);
	}

	private static function renderLista(bool $logado): string {
		if (!PlatformHelpCategoria::tabelasExistem()) {
			return '<div class="alert alert-warning">Documentação ainda não configurada. O Master deve executar <code>database/platform_help.sql</code>.</div>';
		}
		$base = $logado ? rtrim((string)URL, '/').'/painel/ajuda' : rtrim((string)URL, '/').'/ajuda';
		$html = '<h1 class="mt-4 mb-3">Central de ajuda</h1><p class="text-muted">Tutoriais e orientações para usar o Painel CTI.</p>';
		foreach (PlatformHelpCategoria::listAll(true) as $c) {
			$arts = PlatformHelpArtigo::listByCategoria((int)$c->id, true);
			if (!$arts) {
				continue;
			}
			$html .= '<div class="card shadow mb-3"><div class="card-header"><strong>'
				.htmlspecialchars((string)$c->titulo, ENT_QUOTES, 'UTF-8').'</strong></div><ul class="list-group list-group-flush">';
			foreach ($arts as $a) {
				$html .= '<li class="list-group-item">'
					.'<a href="'.$base.'/'.rawurlencode((string)$a->slug).'">'
					.htmlspecialchars((string)$a->titulo, ENT_QUOTES, 'UTF-8').'</a>';
				$temVideo = trim((string)($a->video_url ?? '')) !== '';
				$html .= $temVideo
					? ' <span class="badge bg-success">Vídeo</span>'
					: ' <span class="badge bg-secondary">Vídeo em breve</span>';
				if (!empty($a->resumo)) {
					$html .= '<div class="small text-muted">'.htmlspecialchars((string)$a->resumo, ENT_QUOTES, 'UTF-8').'</div>';
				}
				$html .= '</li>';
			}
			$html .= '</ul></div>';
		}
		return $html;
	}

	private static function renderArtigo(string $slug, bool $logado): string {
		if (!PlatformHelpCategoria::tabelasExistem()) {
			return '<div class="alert alert-warning">Documentação ainda não configurada.</div>';
		}
		$a = PlatformHelpArtigo::getBySlug($slug, true);
		if (!$a) {
			return '<div class="alert alert-danger">Artigo não encontrado.</div>';
		}
		$base = $logado ? rtrim((string)URL, '/').'/painel/ajuda' : rtrim((string)URL, '/').'/ajuda';
		$html = '<ol class="breadcrumb mb-3"><li class="breadcrumb-item"><a href="'.$base.'">Ajuda</a></li>'
			.'<li class="breadcrumb-item active">'.htmlspecialchars((string)$a->titulo, ENT_QUOTES, 'UTF-8').'</li></ol>';
		$html .= '<h1 class="mb-3">'.htmlspecialchars((string)$a->titulo, ENT_QUOTES, 'UTF-8').'</h1>';
		$embed = $a->videoEmbedSrc();
		if ($embed !== '') {
			$vt = htmlspecialchars((string)($a->video_titulo ?: 'Vídeo'), ENT_QUOTES, 'UTF-8');
			$html .= '<div class="ratio ratio-16x9 mb-4 border rounded overflow-hidden">'
				.'<iframe src="'.htmlspecialchars($embed, ENT_QUOTES, 'UTF-8').'" title="'.$vt.'" allowfullscreen loading="lazy"></iframe></div>';
		} else {
			$vt = htmlspecialchars((string)($a->video_titulo ?: 'Tutorial em vídeo'), ENT_QUOTES, 'UTF-8');
			$html .= '<div class="ratio ratio-16x9 mb-4 border rounded bg-light d-flex align-items-center justify-content-center text-center p-3">'
				.'<div><div class="text-muted mb-1"><i class="fas fa-video fa-2x"></i></div>'
				.'<strong>'.$vt.'</strong>'
				.'<div class="small text-muted mt-1">Vídeo em breve — o guia escrito abaixo já está disponível.</div></div></div>';
		}
		$html .= '<div class="ajuda-corpo">'.(string)($a->corpo ?? '').'</div>';
		$html .= '<p class="mt-4"><a href="'.$base.'">&larr; Voltar à lista</a></p>';
		return $html;
	}
}
