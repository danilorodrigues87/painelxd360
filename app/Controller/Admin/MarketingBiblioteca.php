<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\SocialBibliotecaService;

class MarketingBiblioteca extends Page {

	private static function assertAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		$mods = ModuleGateHelper::getModulosEfetivos($idAdmin, $user['usuario']['acesso'] ?? []);
		if (!SocialBibliotecaService::usuarioPodeAcessar($mods)) {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		return true;
	}

	private static function json(array $data): string {
		return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	private static function moduloPainel(): string {
		$user = SessionUser::getUserLogedData();
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		$mods = ModuleGateHelper::getModulosEfetivos($idAdmin, $user['usuario']['acesso'] ?? []);
		return in_array('Redes sociais', $mods, true) ? 'Redes sociais' : 'Campanhas';
	}

	public static function index($request) {
		if (!self::assertAcesso($request)) {
			return '';
		}
		$content = View::render('admin/modules/marketing/biblioteca', []);
		return parent::getPanel(self::moduloPainel(), $content, 'marketing', $request);
	}

	public static function getInfo($request) {
		if (!self::assertAcesso($request, true)) {
			return self::json(['success' => false, 'message' => 'Acesso negado.']);
		}
		TenantHelper::getIdAdmin();
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}

		$post = $request->getPostVars();
		$acao = (string)($post['acao'] ?? '');
		$idAdmin = TenantHelper::getIdAdmin();

		switch ($acao) {
			case 'listar':
				$tipo = trim((string)($post['tipo'] ?? ''));
				$tipo = ($tipo === 'image' || $tipo === 'video') ? $tipo : null;
				$formato = trim((string)($post['formato'] ?? ''));
				$formato = in_array($formato, ['feed', 'story'], true) ? $formato : null;
				$res = SocialBibliotecaService::listar($idAdmin, $tipo, $formato, 200);
				$res['stats'] = SocialBibliotecaService::estatisticas($idAdmin);
				return self::json($res);
			case 'salvar':
				$formato = array_key_exists('formato', $post)
					? (string)($post['formato'] ?? '')
					: null;
				return self::json(SocialBibliotecaService::salvarTitulo(
					(int)($post['id'] ?? 0),
					$idAdmin,
					(string)($post['titulo'] ?? ''),
					$formato
				));
			case 'excluir':
				return self::json(SocialBibliotecaService::excluir(
					(int)($post['id'] ?? 0),
					$idAdmin
				));
			case 'stats':
				return self::json([
					'success' => true,
					'stats' => SocialBibliotecaService::estatisticas($idAdmin),
				]);
			default:
				return self::json(['success' => false, 'message' => 'Ação inválida.']);
		}
	}

	public static function upload($request) {
		if (!self::assertAcesso($request, true)) {
			return self::json(['success' => false, 'message' => 'Acesso negado.']);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$user = SessionUser::getUserLogedData();
		$post = $request->getPostVars() ?: [];
		$file = $_FILES['arquivo'] ?? null;
		if (!is_array($file)) {
			return self::json(['success' => false, 'message' => 'Arquivo ausente.']);
		}
		$formato = trim((string)($post['formato'] ?? ''));
		$res = SocialBibliotecaService::upload(
			$idAdmin,
			$file,
			$formato,
			(int)($user['usuario']['id'] ?? 0) ?: null
		);
		return self::json($res);
	}
}
