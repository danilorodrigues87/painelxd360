<?php

namespace App\Controller\PublicPages;

use App\Utils\View;
use App\Common\Helpers\BrandingHelper;

/**
 * Página pública: exclusão de dados do usuário (exigência Meta / Facebook).
 */
class DataDeletion {

	public static function index($request) {
		$logoUrl = BrandingHelper::urlLogoCti();
		$faviconUrl = BrandingHelper::urlFaviconCti();
		$contato = 'ctieducacional@gmail.com';
		$site = 'https://ctieducacional.com.br';
		$urlBase = rtrim((string)URL, '/');
		$status = '';

		$post = $request->getPostVars() ?: [];
		if (!empty($post['solicitar'])) {
			$status = self::registrarSolicitacao($post);
		}

		$content = View::render('public/data-deletion', [
			'logo_url' => $logoUrl,
			'contato_email' => $contato,
			'site_cti' => $site,
			'url_privacidade' => $urlBase.'/privacidade',
			'url_exclusao' => $urlBase.'/exclusao-de-dados',
			'URL' => $urlBase,
			'status' => $status,
		]);

		return View::render('login/page', [
			'title' => 'Exclusão de dados — CTI Educacional',
			'content' => $content,
			'favicon_url' => $faviconUrl,
		]);
	}

	private static function registrarSolicitacao(array $post): string {
		$nome = trim(strip_tags((string)($post['nome'] ?? '')));
		$email = trim(strip_tags((string)($post['email'] ?? '')));
		$detalhe = trim(strip_tags((string)($post['detalhe'] ?? '')));
		if ($nome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return '<div class="alert alert-danger">Informe nome e um e-mail válido.</div>';
		}
		$dir = dirname(__DIR__, 3).'/uploads/data-deletion';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$linha = json_encode([
			'em' => date('c'),
			'nome' => $nome,
			'email' => $email,
			'detalhe' => $detalhe,
			'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
		], JSON_UNESCAPED_UNICODE)."\n";
		@file_put_contents($dir.'/solicitacoes.log', $linha, FILE_APPEND | LOCK_EX);

		$contato = 'ctieducacional@gmail.com';
		return '<div class="alert alert-success">Solicitação registrada. Responderemos em até <strong>30 dias</strong> no e-mail informado. '
			.'Dúvidas: <a href="mailto:'.htmlspecialchars($contato).'">'.htmlspecialchars($contato).'</a>.</div>';
	}
}
