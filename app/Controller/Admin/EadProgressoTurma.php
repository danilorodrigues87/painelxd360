<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\LmsAdminProgressoHelper;

class EadProgressoTurma extends Page {

	private static function assertAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		$mods = ModuleGateHelper::getModulosEfetivos($idAdmin, $user['usuario']['acesso'] ?? []);
		$ok = in_array('Cursos Online', $mods, true);
		if (!$ok) {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		return true;
	}

	public static function index($request) {
		if (!self::assertAcesso($request)) {
			return '';
		}
		$content = View::render('admin/modules/ead/progresso-turma', []);
		return parent::getPanel('Progresso EAD', $content, 'portal_ead', $request);
	}

	public static function getInfo($request) {
		if (!self::assertAcesso($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';
		$filtros = [
			'id_curso' => (int)($post['id_curso'] ?? 0),
			'q' => (string)($post['q'] ?? ''),
			'status' => (string)($post['status'] ?? 'all'),
			'min_pct' => (int)($post['min_pct'] ?? 0),
		];

		if ($acao === 'listar') {
			$res = LmsAdminProgressoHelper::resumoTurma($idAdmin, $filtros);
			return json_encode([
				'success' => !empty($res['ok']),
				'message' => $res['message'] ?? '',
				'cursos' => $res['cursos'] ?? [],
				'itens' => $res['itens'] ?? [],
				'totais' => $res['totais'] ?? [],
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		if ($acao === 'csv') {
			$res = LmsAdminProgressoHelper::exportarCsvTurma($idAdmin, $filtros);
			return json_encode([
				'success' => !empty($res['ok']),
				'message' => $res['message'] ?? '',
				'filename' => $res['filename'] ?? 'progresso.csv',
				'csv' => $res['csv'] ?? '',
			], JSON_UNESCAPED_UNICODE);
		}

		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}
}
