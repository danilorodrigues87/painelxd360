<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Session\User\Login as SessionUser;
use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\TenantHelper;
use App\Common\Helpers\SaasAssinaturaService;
use App\Common\ProductModules;
use App\Model\Entity\ClientesAssinantes;

class Home extends Page {

	public static function index($request) {
		$user = SessionUser::getUserLogedData();
		$nome = htmlspecialchars((string)($user['usuario']['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
		$empresa = htmlspecialchars((string)($user['escola']['nome'] ?? ''), ENT_QUOTES, 'UTF-8');
		$idAdmin = TenantHelper::getIdAdmin();

		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		$status = $escola instanceof ClientesAssinantes ? (string)($escola->assinatura_status ?? 'ativa') : 'ativa';
		$valor = $escola instanceof ClientesAssinantes ? SaasAssinaturaService::resolverValorMensal($escola) : 0;
		$trial = '';
		if ($escola instanceof ClientesAssinantes && !empty($escola->trial_ate)) {
			$trial = '<small class="text-muted d-block">Trial até '.date('d/m/Y', strtotime((string)$escola->trial_ate)).'</small>';
		}

		$produtos = ModuleGateHelper::getSlugsProdutos($idAdmin);
		$listaProdutos = '';
		foreach ($produtos as $slug) {
			$label = ProductModules::slugParaLabel($slug) ?? $slug;
			$listaProdutos .= '<li class="list-group-item d-flex justify-content-between align-items-center">'
				.htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
				.'<span class="badge bg-success">Ativo</span></li>';
		}
		if ($listaProdutos === '') {
			$listaProdutos = '<li class="list-group-item text-muted">Nenhum produto liberado.</li>';
		}

		$content = View::render('admin/modules/home/index', [
			'nome_usuario'      => $nome,
			'nome_empresa'      => $empresa,
			'status_assinatura' => htmlspecialchars($status, ENT_QUOTES, 'UTF-8'),
			'valor_mensal'      => number_format($valor, 2, ',', '.'),
			'trial_ate'         => $trial,
			'qtd_produtos'      => (string)count($produtos),
			'lista_produtos'    => $listaProdutos,
		]);

		return parent::getPanel('Dashboard', $content, 'Dashboard');
	}
}
