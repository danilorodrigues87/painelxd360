<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\LmsPresencaHelper;

class EadAlunosOnline extends Page {

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
		if (!LmsPresencaHelper::tabelasExistem()) {
			$content = View::render('admin/modules/ead/alunos-online-sql', []);
			return parent::getPanel('Alunos online', $content, 'portal_ead', $request);
		}
		$content = View::render('admin/modules/ead/alunos-online', []);
		return parent::getPanel('Alunos online', $content, 'portal_ead', $request);
	}

	public static function getInfo($request) {
		try {
			if (!self::assertAcesso($request, true)) {
				return json_encode(['success' => false, 'message' => 'Acesso negado.']);
			}
			if (!LmsPresencaHelper::tabelasExistem()) {
				return json_encode([
					'success' => false,
					'message' => 'Execute database/lms_portal_presenca.sql no phpMyAdmin.',
				]);
			}
			$post = $request->getPostVars();
			if (($post['acao'] ?? '') !== 'listar') {
				return json_encode(['success' => false, 'message' => 'Ação inválida.']);
			}
			$idAdmin = TenantHelper::getIdAdmin();
			$lista = LmsPresencaHelper::listarOnline($idAdmin);
			$emAula = 0;
			$navegando = 0;
			foreach ($lista as $item) {
				if (($item['status'] ?? '') === 'em_aula') {
					$emAula++;
				} else {
					$navegando++;
				}
			}
			return json_encode([
				'success' => true,
				'total' => count($lista),
				'em_aula' => $emAula,
				'navegando' => $navegando,
				'alunos' => $lista,
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		} catch (\Throwable $e) {
			return json_encode([
				'success' => false,
				'message' => 'Erro: '.$e->getMessage(),
			], JSON_UNESCAPED_UNICODE);
		}
	}
}
