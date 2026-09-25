<?php

namespace App\Controller\Master;

use App\Common\Helpers\SaasContratoService;
use App\Common\ProductModules;
use App\Model\Entity\SaasContrato;
use App\Utils\View;

class Contratos extends Page {

	public static function index($request) {
		$content = View::render('master/modules/contratos/index', [
			'produtos_json' => json_encode(ProductModules::listarComModo(), JSON_UNESCAPED_UNICODE),
			'tabela_ok' => SaasContrato::tabelaExiste() ? '1' : '0',
		]);
		return parent::getPanel('Contratos', $content, 'contratos');
	}

	public static function getInfo($request) {
		$post = $request->getPostVars();
		$acao = $post['acao'] ?? '';
		if ($acao === 'listar') {
			return json_encode([
				'success' => true,
				'contratos' => SaasContratoService::listarFiltrado([
					'q' => (string)($post['q'] ?? ''),
					'status' => (string)($post['status'] ?? ''),
					'produto' => (string)($post['produto'] ?? ''),
					'aceite' => (string)($post['aceite'] ?? ''),
				]),
			], JSON_UNESCAPED_UNICODE);
		}
		if ($acao === 'visualizar') {
			return json_encode([
				'success' => true,
				'html' => SaasContratoService::htmlVisualizacao((int)($post['id'] ?? 0)),
			], JSON_UNESCAPED_UNICODE);
		}
		if ($acao === 'cancelar') {
			$r = SaasContratoService::cancelar((int)($post['id'] ?? 0));
			return json_encode(['success' => $r['ok'], 'message' => $r['message']], JSON_UNESCAPED_UNICODE);
		}
		return json_encode(['success' => false, 'message' => 'Ação inválida.']);
	}
}
