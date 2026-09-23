<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\ContratoTemplateHelper;
use App\Common\Helpers\ContratoVariaveisBuilder;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\CategoryCourses;

class ConfigContrato extends Page {

	private static function assertAcesso($request, bool $api = false): bool {
		$user = SessionUser::getUserLogedData();
		if (($user['usuario']['nivel'] ?? '') !== 'Diretor') {
			if (!$api) {
				$request->getRouter()->redirect('/painel');
			}
			return false;
		}
		$idAdmin = (int)($user['usuario']['id_admin'] ?? 0);
		if (!in_array('contratos', ModuleGateHelper::getSlugsEscola($idAdmin), true)) {
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
		$content = View::render('admin/modules/config/contrato', []);
		return parent::getPanel('Modelo de contrato', $content, 'config');
	}

	public static function getInfo($request) {
		if (!self::assertAcesso($request, true)) {
			return json_encode(['success' => false, 'message' => 'Acesso negado.']);
		}

		$postVars = $request->getPostVars();
		$acao = $postVars['acao'] ?? '';

		if ($acao === 'carregar') {
			return self::carregar();
		}
		if ($acao === 'salvar') {
			return self::salvar($postVars);
		}
		if ($acao === 'restaurar') {
			return self::restaurar();
		}
		if ($acao === 'salvar_certificado') {
			return self::salvarCertificado($postVars);
		}
		if ($acao === 'preview') {
			return self::preview($postVars);
		}

		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}

	private static function carregar(): string {
		$idAdmin = TenantHelper::getIdAdmin();
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		$colOk = ClientesAssinantes::temColunaModeloContrato();
		$fraseOk = ClientesAssinantes::temColunaCertificadoFrase();

		$custom = '';
		$usandoPadrao = true;
		if ($escola instanceof ClientesAssinantes && $colOk) {
			$custom = trim((string)($escola->modelo_contrato_html ?? ''));
			$usandoPadrao = ($custom === '');
		}

		$htmlEditor = $usandoPadrao
			? ContratoTemplateHelper::modeloPadrao()
			: $custom;

		$frase = 'Concluiu com louvor o curso de';
		$fraseCustom = false;
		if ($escola instanceof ClientesAssinantes && $fraseOk) {
			$f = trim((string)($escola->certificado_frase_conclusao ?? ''));
			if ($f !== '') {
				$frase = $f;
				$fraseCustom = true;
			}
		}

		$vars = [];
		foreach (ContratoTemplateHelper::catalogoVariaveis() as $k => $desc) {
			$vars[] = ['chave' => $k, 'descricao' => $desc];
		}

		$categorias = CategoryCourses::listarResumoEscola($idAdmin);

		return json_encode([
			'success'         => true,
			'coluna_ok'       => $colOk,
			'frase_coluna_ok' => $fraseOk,
			'usando_padrao'   => $usandoPadrao,
			'html'            => $htmlEditor,
			'html_padrao'     => ContratoTemplateHelper::modeloPadrao(),
			'variaveis'       => $vars,
			'categorias'      => $categorias,
			'contrato_categoria_coluna_ok' => CategoryCourses::temColunaContrato(),
			'certificado'     => [
				'frase_conclusao' => $frase,
				'usando_padrao'   => !$fraseCustom,
			],
		], JSON_UNESCAPED_UNICODE);
	}

	private static function salvar(array $postVars): string {
		if (!ClientesAssinantes::temColunaModeloContrato()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute o SQL database/escolas_modelo_contrato.sql no phpMyAdmin.',
			]);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola instanceof ClientesAssinantes) {
			return json_encode(['success' => false, 'message' => 'Escola não encontrada.']);
		}

		$html = (string)($postVars['html'] ?? '');
		if (trim($html) === '') {
			return json_encode(['success' => false, 'message' => 'Informe o HTML do contrato ou use Restaurar padrão.']);
		}

		if (!ClientesAssinantes::salvarModeloContrato((int)$escola->id, $html)) {
			return json_encode(['success' => false, 'message' => 'Falha ao salvar.']);
		}

		return json_encode([
			'success' => true,
			'message' => 'Modelo de contrato salvo. Novos “Ver Contrato” usarão este texto.',
		]);
	}

	private static function restaurar(): string {
		if (!ClientesAssinantes::temColunaModeloContrato()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute o SQL database/escolas_modelo_contrato.sql no phpMyAdmin.',
			]);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola instanceof ClientesAssinantes) {
			return json_encode(['success' => false, 'message' => 'Escola não encontrada.']);
		}

		ClientesAssinantes::salvarModeloContrato((int)$escola->id, null);

		return json_encode([
			'success' => true,
			'message' => 'Padrão CTI restaurado (mesmo texto da escola 1 / Capão Bonito).',
			'html'    => ContratoTemplateHelper::modeloPadrao(),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function salvarCertificado(array $postVars): string {
		if (!ClientesAssinantes::temColunaCertificadoFrase()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute o SQL database/escolas_modelo_contrato.sql no phpMyAdmin.',
			]);
		}
		$idAdmin = TenantHelper::getIdAdmin();
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola instanceof ClientesAssinantes) {
			return json_encode(['success' => false, 'message' => 'Escola não encontrada.']);
		}

		$frase = trim((string)($postVars['frase_conclusao'] ?? ''));
		$padrao = 'Concluiu com louvor o curso de';
		$salvar = ($frase === '' || $frase === $padrao) ? null : $frase;

		if (!ClientesAssinantes::salvarFraseCertificado((int)$escola->id, $salvar)) {
			return json_encode(['success' => false, 'message' => 'Falha ao salvar.']);
		}

		return json_encode([
			'success' => true,
			'message' => $salvar === null
				? 'Frase do certificado restaurada ao padrão.'
				: 'Frase do certificado salva.',
		]);
	}

	private static function preview(array $postVars): string {
		$user = SessionUser::getUserLogedData();
		$escolaSession = $user['escola'] ?? [];
		if (!is_array($escolaSession)) {
			$escolaSession = [];
		}

		$html = (string)($postVars['html'] ?? '');
		if (trim($html) === '') {
			$idAdmin = TenantHelper::getIdAdmin();
			$escola = ClientesAssinantes::getEscolaById($idAdmin);
			$html = ContratoTemplateHelper::resolverModelo($escola instanceof ClientesAssinantes ? $escola : null);
		}

		$opts = [
			'id_categoria' => (int)($postVars['id_categoria'] ?? 0),
			'menor'          => !empty($postVars['menor']),
			'pagamento'      => (string)($postVars['pagamento'] ?? 'parcelado'),
		];
		$vars = ContratoVariaveisBuilder::dadosExemplo($escolaSession, $opts);
		$render = ContratoTemplateHelper::aplicar($html, $vars);

		$idCat = (int)($postVars['id_categoria'] ?? 0);
		$catIncompleta = false;
		if ($idCat > 0 && CategoryCourses::temColunaContrato()) {
			$cat = CategoryCourses::getCategoryById($idCat);
			$catIncompleta = !CategoryCourses::contratoEstaCompleto($cat);
		}

		return json_encode([
			'success'          => true,
			'preview'          => $render,
			'categoria_incompleta' => $catIncompleta,
			'categoria_id'     => $idCat,
		], JSON_UNESCAPED_UNICODE);
	}
}
