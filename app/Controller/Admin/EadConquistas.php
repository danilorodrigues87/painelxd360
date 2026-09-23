<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\LmsConquistaHelper;
use App\Model\Entity\User as EntityUser;

class EadConquistas extends Page {

	private static function assertAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		$mods = ModuleGateHelper::getModulosEfetivos($idAdmin, $user['usuario']['acesso'] ?? []);
		$ok = in_array('Conquistas EAD', $mods, true);
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
		$content = View::render('admin/modules/ead/conquistas', []);
		return parent::getPanel('Conquistas EAD', $content, 'portal_ead', $request);
	}

	public static function getInfo($request) {
		if (!self::assertAcesso($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}
		if (!LmsConquistaHelper::tabelasExistem()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute database/lms_conquistas.sql e lms_conquistas_v2.sql no phpMyAdmin.',
			]);
		}
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';
		$idAdmin = TenantHelper::getIdAdmin();

		switch ($acao) {
			case 'listar':
				return json_encode([
					'success' => true,
					'conquistas' => LmsConquistaHelper::listParaEscolaAdmin($idAdmin),
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			case 'toggle':
				$slug = trim((string)($post['slug'] ?? ''));
				$ativo = !empty($post['ativo']);
				$res = LmsConquistaHelper::setEscolaAtivo($idAdmin, $slug, $ativo);
				return json_encode([
					'success' => !empty($res['ok']),
					'message' => $res['message'] ?? '',
				], JSON_UNESCAPED_UNICODE);
			case 'buscar_alunos':
				$q = trim((string)($post['q'] ?? ''));
				return self::buscarAlunos($idAdmin, $q);
			case 'liberar':
				$idAluno = (int)($post['id_aluno'] ?? 0);
				$slug = trim((string)($post['slug'] ?? ''));
				if ($idAluno <= 0 || $slug === '') {
					return json_encode(['success' => false, 'message' => 'Informe aluno e conquista.']);
				}
				$aluno = EntityUser::getUserById($idAluno);
				if (!$aluno || (int)$aluno->id_admin !== $idAdmin || ($aluno->nivel ?? '') !== 'Cliente') {
					return json_encode(['success' => false, 'message' => 'Aluno inválido.']);
				}
				$res = LmsConquistaHelper::concederManual($idAdmin, $idAluno, $slug);
				return json_encode([
					'success' => !empty($res['ok']),
					'message' => $res['message'] ?? '',
				], JSON_UNESCAPED_UNICODE);
			default:
				return json_encode(['success' => false, 'message' => 'Ação inválida.']);
		}
	}

	private static function buscarAlunos(int $idAdmin, string $q): string {
		$where = "nivel = 'Cliente' AND id_admin = ".(int)$idAdmin;
		if ($q !== '') {
			$safe = addslashes($q);
			$where .= ' AND (nome LIKE "%'.$safe.'%" OR email LIKE "%'.$safe.'%")';
		}
		$stmt = EntityUser::getUser($where, 'nome ASC', '30', 'id, nome, email');
		$lista = [];
		while ($u = $stmt->fetchObject()) {
			$lista[] = [
				'id' => (int)$u->id,
				'nome' => (string)$u->nome,
				'email' => (string)$u->email,
			];
		}
		return json_encode(['success' => true, 'alunos' => $lista], JSON_UNESCAPED_UNICODE);
	}
}
