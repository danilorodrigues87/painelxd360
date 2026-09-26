<?php

namespace App\Controller\Master;

use App\Utils\View;
use App\Common\Helpers\SaasContratoTemplateHelper;
use App\Common\Helpers\SaasContratoVariaveisBuilder;
use App\Common\Helpers\SaasEmpresaXd360Helper;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\PlanosAssinatura;
use App\Model\Entity\SaasContratoModelo;
use App\Model\Entity\SaasEmpresaXd360;

class ContratoSaas extends Page {

	public static function index($request) {
		if (!SaasContratoModelo::tabelaExiste()) {
			$content = View::render('master/modules/contrato_saas/sql', []);
			return parent::getPanel('Contrato SaaS — Master', $content, 'contrato_saas');
		}

		$content = View::render('master/modules/contrato_saas/index', []);
		return parent::getPanel('Contrato SaaS — Master', $content, 'contrato_saas');
	}

	public static function getInfo($request) {
		if (!SaasContratoModelo::tabelaExiste()) {
			return json_encode([
				'success' => false,
				'message' => 'Execute database/xd360/10_saas_contrato.sql no phpMyAdmin.',
			]);
		}

		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';

		switch ($acao) {
			case 'carregar':
				return self::carregar();
			case 'salvar':
				return self::salvar($post);
			case 'restaurar':
				return self::restaurar();
			case 'preview':
				return self::preview($post);
			default:
				return json_encode(['success' => false, 'message' => 'Ação inválida.']);
		}
	}

	private static function carregar(): string {
		$row = SaasContratoModelo::get();
		$custom = '';
		$usandoPadrao = true;
		if ($row instanceof SaasContratoModelo) {
			$custom = trim((string)($row->html ?? ''));
			$usandoPadrao = ($custom === '');
		}

		$htmlEditor = $usandoPadrao ? SaasContratoTemplateHelper::modeloPadrao() : $custom;

		$vars = [];
		foreach (SaasContratoTemplateHelper::catalogoVariaveis() as $k => $desc) {
			$vars[] = ['chave' => $k, 'descricao' => $desc];
		}

		$empCheck = SaasEmpresaXd360Helper::checarCompleto(SaasEmpresaXd360::get());

		return json_encode([
			'success'       => true,
			'tabela_ok'     => true,
			'usando_padrao' => $usandoPadrao,
			'html'          => $htmlEditor,
			'html_padrao'   => SaasContratoTemplateHelper::modeloPadrao(),
			'variaveis'     => $vars,
			'escolas'       => self::listarEscolasResumo(),
			'planos'        => Planos::listarAtivosResumo(),
			'dados_xd360_ok'  => $empCheck['ok'],
			'dados_xd360_faltando' => $empCheck['faltando'],
		], JSON_UNESCAPED_UNICODE);
	}

	private static function salvar(array $post): string {
		$html = (string)($post['html'] ?? '');
		if (trim($html) === '') {
			return json_encode(['success' => false, 'message' => 'Informe o HTML do contrato ou use Restaurar padrão.']);
		}
		if (!SaasContratoModelo::salvar($html)) {
			return json_encode(['success' => false, 'message' => 'Falha ao salvar.']);
		}
		return json_encode([
			'success' => true,
			'message' => 'Modelo de contrato SaaS salvo. Clientes passam a ver este texto em Assinatura → Ver contrato.',
		]);
	}

	private static function restaurar(): string {
		if (!SaasContratoModelo::salvar(null)) {
			return json_encode(['success' => false, 'message' => 'Falha ao restaurar.']);
		}
		return json_encode([
			'success' => true,
			'message' => 'Padrão XD360 restaurado (arquivo modelo_padrao.html).',
			'html'    => SaasContratoTemplateHelper::modeloPadrao(),
		], JSON_UNESCAPED_UNICODE);
	}

	private static function preview(array $post): string {
		$html = (string)($post['html'] ?? '');
		if (trim($html) === '') {
			$html = SaasContratoTemplateHelper::resolverModelo();
		}

		$opts = [
			'id_escola' => (int)($post['id_escola'] ?? 0),
			'id_plano'  => (int)($post['id_plano'] ?? 0),
		];
		$vars = SaasContratoVariaveisBuilder::dadosExemplo($opts);
		$render = SaasContratoTemplateHelper::aplicar($html, $vars);
		$faltando = SaasContratoVariaveisBuilder::listarPendencias(
			self::escolaPreview($opts)
		);

		return json_encode([
			'success' => true,
			'preview' => $render,
			'pendencias' => $faltando,
		], JSON_UNESCAPED_UNICODE);
	}

	/** @param array<string,mixed> $opts */
	private static function escolaPreview(array $opts): ?ClientesAssinantes {
		$id = (int)($opts['id_escola'] ?? 0);
		if ($id <= 0) {
			return null;
		}
		$e = ClientesAssinantes::getEscolaById($id);
		return $e instanceof ClientesAssinantes ? $e : null;
	}

	/** @return array<int,array{id:int,nome:string}> */
	private static function listarEscolasResumo(): array {
		$out = [];
		$results = ClientesAssinantes::getEscolas(null, 'nome ASC');
		while ($e = $results->fetchObject(ClientesAssinantes::class)) {
			$out[] = [
				'id'   => (int)$e->id,
				'nome' => (string)$e->nome,
			];
		}
		return $out;
	}
}
