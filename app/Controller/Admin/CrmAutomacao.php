<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\CrmAutomacaoTemplateHelper;
use App\Model\Entity\EscolaIntegracoes;

/**
 * Templates editáveis de WhatsApp automático do CRM (Fase 5+, Diretor).
 */
class CrmAutomacao extends Page {

	private static function assertDiretor($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor') {
			if (!$api) {
				$request->getRouter()->redirect('/painel/crm');
			}
			return false;
		}
		return true;
	}

	public static function index($request) {
		if (!self::assertDiretor($request)) {
			return '';
		}
		$content = View::render('admin/modules/crm/automacao', []);
		return parent::getPanel('Leads', $content, 'CRM', $request);
	}

	public static function getInfo($request) {
		header('Content-Type: application/json; charset=utf-8');
		if (!self::assertDiretor($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso restrito ao Diretor.']);
		}

		$post = $request->getPostVars();
		$acao = (string)($post['acao'] ?? 'carregar');

		if ($acao === 'carregar') {
			return self::carregar();
		}
		if ($acao === 'salvar') {
			return self::salvar($post);
		}
		if ($acao === 'restaurar') {
			return self::restaurar();
		}
		if ($acao === 'preview') {
			return self::preview($post);
		}

		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function carregar(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$cfg = CrmAutomacaoTemplateHelper::configuracaoEscola($idAdmin);

		return json_encode(array_merge(['success' => true], $cfg), JSON_UNESCAPED_UNICODE);
	}

	private static function salvar(array $post): string {
		if (!EscolaIntegracoes::temColunasCrmAutomacao()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute o SQL database/crm_automacao_wa.sql no phpMyAdmin.',
			]);
		}

		$idAdmin = TenantHelper::getIdAdmin();
		if (!CrmAutomacaoTemplateHelper::salvarConfiguracao($idAdmin, $post)) {
			return json_encode(['success' => false, 'message' => 'Falha ao salvar configuração.']);
		}

		return json_encode([
			'success' => true,
			'message' => 'Templates de automação CRM salvos.',
		]);
	}

	private static function restaurar(): string {
		if (!EscolaIntegracoes::temColunasCrmAutomacao()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute o SQL database/crm_automacao_wa.sql no phpMyAdmin.',
			]);
		}

		$idAdmin = TenantHelper::getIdAdmin();
		if (!CrmAutomacaoTemplateHelper::restaurarPadrao($idAdmin)) {
			return json_encode(['success' => false, 'message' => 'Falha ao restaurar.']);
		}

		return json_encode([
			'success' => true,
			'message' => 'Textos padrão CTI restaurados para os três status.',
		], JSON_UNESCAPED_UNICODE);
	}

	private static function preview(array $post): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$status = trim((string)($post['status'] ?? 'novo'));
		$texto = trim((string)($post['mensagem'] ?? ''));

		if ($texto === '') {
			$texto = CrmAutomacaoTemplateHelper::mensagemPadrao($status) ?? '';
		}

		$leadFake = (object)[
			'nome'            => trim((string)($post['nome_exemplo'] ?? 'Maria Silva')),
			'curso_interesse' => trim((string)($post['curso_exemplo'] ?? 'Informática')),
		];

		$render = CrmAutomacaoTemplateHelper::aplicar($texto, $leadFake, $idAdmin);

		return json_encode([
			'success'  => true,
			'preview'  => $render,
		], JSON_UNESCAPED_UNICODE);
	}
}
