<?php

namespace App\Controller\Admin;

use App\Common\Helpers\ModuleGateHelper;
use App\Common\Helpers\TenantHelper;
use App\Common\ProductModules;
use App\Common\Environment;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\ProdutoLaunchToken;

class ProdutosLaunch extends Page {

	public static function abrir($request, string $slugProduto) {
		$slugProduto = trim($slugProduto);
		if ($slugProduto === '' || ProductModules::slugParaLabel($slugProduto) === null) {
			$request->getRouter()->redirect('/painel/produtos');
		}

		$idAdmin = TenantHelper::getIdAdmin();
		$userId = TenantHelper::getUsuarioId();
		if ($idAdmin <= 0 || $userId <= 0) {
			$request->getRouter()->redirect('/login');
		}

		if (!in_array($slugProduto, ModuleGateHelper::getSlugsProdutos($idAdmin), true)) {
			$request->getRouter()->redirect('/painel/produtos');
		}

		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola instanceof ClientesAssinantes || !$escola->isAtiva()) {
			$request->getRouter()->redirect('/painel/produtos');
		}
		if ((string)($escola->assinatura_status ?? '') === 'suspensa') {
			$request->getRouter()->redirect('/painel/assinatura');
		}

		try {
			$plain = ProdutoLaunchToken::criar($idAdmin, $userId, $slugProduto);
		} catch (\Throwable $e) {
			error_log('[ProdutosLaunch] '.$e->getMessage());
			$request->getRouter()->redirect('/painel/produtos');
		}

		$dest = self::urlAppProduto($slugProduto, $plain);
		$request->getRouter()->redirect($dest);
	}

	private static function urlAppProduto(string $slug, string $token): string {
		$map = [
			'doceflow' => 'DOCEFLOW_PUBLIC_URL',
		];
		$envKey = $map[$slug] ?? ('PRODUCT_'.strtoupper($slug).'_PUBLIC_URL');
		$explicit = rtrim((string)Environment::get($envKey, ''), '/');
		if ($explicit === '') {
			$explicit = rtrim((string)Environment::get('DOCEFLOW_PUBLIC_URL', ''), '/');
		}

		if ($explicit !== '') {
			return $explicit.'/?launch='.urlencode($token);
		}

		// Opção A: path relativo no host do tenant
		$path = trim((string)Environment::get('PRODUCT_DOCEFLOW_PATH', '/doceflow'), '/');
		if ($slug !== 'doceflow') {
			$path = trim((string)Environment::get('PRODUCT_'.$slug.'_PATH', '/'.$slug), '/');
		}
		return '/'.$path.'/?launch='.urlencode($token);
	}
}
