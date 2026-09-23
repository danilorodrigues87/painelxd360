<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Common\ProductModules;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\SaasAssinaturaService;
use App\Model\Entity\ClientesAssinantes;

class Produtos extends Page {

	public static function index($request) {
		$idAdmin = TenantHelper::getIdAdmin();
		$slugs = ModuleGateHelper::getSlugsProdutos($idAdmin);
		$cards = '';

		foreach ($slugs as $slug) {
			$label = ProductModules::slugParaLabel($slug) ?? $slug;
			$launchUrl = URL.'/painel/produtos/abrir/'.rawurlencode($slug);
			$demoUrl = ProductModules::urlProduto($slug);
			$btn = '<a href="'.htmlspecialchars($launchUrl, ENT_QUOTES, 'UTF-8').'" class="btn btn-primary btn-sm">Abrir app</a>';
			if ($demoUrl && $slug !== 'doceflow') {
				$btn .= ' <a href="'.htmlspecialchars($demoUrl, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">Demo HTML</a>';
			} elseif ($demoUrl && $slug === 'doceflow') {
				$btn .= ' <a href="'.htmlspecialchars($demoUrl, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">Preview estático</a>';
			}
			$cards .= '<div class="col-md-6 col-xl-4 mb-3"><div class="card h-100 shadow-sm">'
				.'<div class="card-body d-flex flex-column">'
				.'<h5 class="card-title">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</h5>'
				.'<p class="text-muted small flex-grow-1">Produto licenciado no seu plano XD360.</p>'
				.$btn
				.'</div></div></div>';
		}

		if ($cards === '') {
			$cards = '<div class="col-12"><div class="alert alert-info">Nenhum produto liberado no momento. '
				.'<a href="'.URL.'/painel/assinatura">Ver assinatura</a></div></div>';
		}

		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		$status = $escola instanceof ClientesAssinantes ? (string)($escola->assinatura_status ?? '') : '';
		$valor = $escola instanceof ClientesAssinantes ? SaasAssinaturaService::resolverValorMensal($escola) : 0;

		$content = View::render('admin/modules/produtos/index', [
			'cards_html' => $cards,
			'status_assinatura' => htmlspecialchars($status, ENT_QUOTES, 'UTF-8'),
			'valor_mensal' => number_format($valor, 2, ',', '.'),
		]);

		return parent::getPanel('Meus Produtos', $content, 'produtos');
	}
}
