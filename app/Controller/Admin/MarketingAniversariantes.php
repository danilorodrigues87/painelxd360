<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\AniversariantesHelper;

class MarketingAniversariantes extends Page {

	private static function assertAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		$mods = ModuleGateHelper::getModulosEfetivos($idAdmin, $user['usuario']['acesso'] ?? []);
		if (!in_array('Campanhas', $mods, true)) {
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

	public static function index($request) {
		if (!self::assertAcesso($request)) {
			return '';
		}
		$content = View::render('admin/modules/marketing/aniversariantes', []);
		return parent::getPanel('Campanhas', $content, 'marketing', $request);
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

		if ($acao === 'listar') {
			return self::listar($post);
		}

		return self::json(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function listar(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$periodo = (string)($post['periodo'] ?? 'mes');
		$busca = trim((string)($post['busca'] ?? ''));
		$mes = isset($post['mes']) ? (int)$post['mes'] : null;
		$situacao = AniversariantesHelper::normalizarSituacao((string)($post['situacao'] ?? 'todos'));

		$lista = AniversariantesHelper::listar($idAdmin, $periodo, $busca, $mes, $situacao);

		return self::json([
			'success' => true,
			'lista' => $lista,
			'total' => count($lista),
			'periodo' => $periodo,
			'mes' => $mes ?? (int)date('m'),
			'situacao' => $situacao,
			'situacoes' => AniversariantesHelper::situacoesDisponiveis(),
		]);
	}
}
