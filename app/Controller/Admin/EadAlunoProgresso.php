<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\LmsAdminProgressoHelper;
use App\Model\Entity\User as EntityUser;

class EadAlunoProgresso extends Page {

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

	public static function index($request, $idAluno) {
		if (!self::assertAcesso($request)) {
			return '';
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$idAluno = (int)$idAluno;
		$aluno = EntityUser::getUserById($idAluno);
		if (!$aluno || (int)$aluno->id_admin !== $idAdmin || ($aluno->nivel ?? '') !== 'Cliente') {
			$request->getRouter()->redirect('/painel/clientes');
			return '';
		}
		$content = View::render('admin/modules/ead/aluno-progresso', [
			'id_aluno' => (string)$idAluno,
			'nome_aluno' => htmlspecialchars((string)$aluno->nome, ENT_QUOTES, 'UTF-8'),
			'email_aluno' => htmlspecialchars((string)$aluno->email, ENT_QUOTES, 'UTF-8'),
		]);
		return parent::getPanel('Progresso EAD', $content, 'portal_ead', $request);
	}

	public static function getInfo($request, $idAluno) {
		if (!self::assertAcesso($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$idAluno = (int)$idAluno;
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		if ($acao === 'historico') {
			$res = LmsAdminProgressoHelper::historicoAluno($idAdmin, $idAluno);
			return json_encode([
				'success' => !empty($res['ok']),
				'message' => $res['message'] ?? '',
				'aluno' => $res['aluno'] ?? null,
				'cursos' => $res['cursos'] ?? [],
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		if ($acao === 'liberar_proxima') {
			$idCurso = (int)($post['id_curso'] ?? 0);
			$res = LmsAdminProgressoHelper::liberarProxima($idAdmin, $idAluno, $idCurso);
			return json_encode([
				'success' => !empty($res['ok']),
				'message' => $res['message'] ?? '',
			], JSON_UNESCAPED_UNICODE);
		}

		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}
}
