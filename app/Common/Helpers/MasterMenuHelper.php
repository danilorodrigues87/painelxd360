<?php

namespace App\Common\Helpers;

use App\Utils\View;

class MasterMenuHelper {

	private static function grupos(): array {
		return [
			[
				'id'    => 'dashboard',
				'label' => 'Dashboard',
				'icon'  => 'fas fa-tachometer-alt',
				'items' => [
					['id' => 'home', 'label' => 'Dashboard', 'url' => '/master', 'icon' => 'fas fa-tachometer-alt'],
				],
			],
			[
				'id'    => 'negocio',
				'label' => 'Clientes & negócio',
				'icon'  => 'fas fa-building',
				'items' => [
					['id' => 'escolas', 'label' => 'Clientes', 'url' => '/master/clientes', 'icon' => 'fas fa-users'],
					['id' => 'planos', 'label' => 'Planos', 'url' => '/master/planos', 'icon' => 'fas fa-box-open'],
					['id' => 'assinaturas', 'label' => 'Assinaturas', 'url' => '/master/assinaturas', 'icon' => 'fas fa-file-invoice-dollar'],
					['id' => 'contrato_saas', 'label' => 'Contrato SaaS', 'url' => '/master/contrato-saas', 'icon' => 'fas fa-file-contract'],
					['id' => 'dados_xd360', 'label' => 'Dados jurídicos XD360', 'url' => '/master/dados-xd360', 'icon' => 'fas fa-building'],
					['id' => 'chamados', 'label' => 'Chamados', 'url' => '/master/chamados', 'icon' => 'fas fa-headset'],
				],
			],
			[
				'id'    => 'conteudo',
				'label' => 'Conteúdo',
				'icon'  => 'fas fa-book-open',
				'items' => [
					['id' => 'documentacao', 'label' => 'Documentação', 'url' => '/master/documentacao', 'icon' => 'fas fa-book'],
				],
			],
			[
				'id'    => 'conta',
				'label' => 'Conta',
				'icon'  => 'fas fa-user-cog',
				'items' => [
					['id' => 'usuarios', 'label' => 'Usuários Master', 'url' => '/master/usuarios', 'icon' => 'fas fa-users-cog'],
					['id' => 'perfil', 'label' => 'Meu perfil', 'url' => '/master/perfil', 'icon' => 'fas fa-user-cog'],
				],
			],
		];
	}

	private static function grupoDoItem(string $menuAtivo): ?string {
		foreach (self::grupos() as $grupo) {
			foreach ($grupo['items'] as $item) {
				if (($item['id'] ?? '') === $menuAtivo) {
					return (string)$grupo['id'];
				}
			}
		}
		return null;
	}

	public static function render(string $menuAtivo): string {
		$grupoAtivo = self::grupoDoItem($menuAtivo);
		$html = '<div class="nav"><div class="sb-sidenav-menu-heading">XD360 Master</div>';

		foreach (self::grupos() as $grupo) {
			$gid = (string)$grupo['id'];
			$items = $grupo['items'] ?? [];
			if (count($items) === 1 && $gid === 'dashboard') {
				$item = $items[0];
				$active = $menuAtivo === ($item['id'] ?? '') ? 'active' : '';
				$html .= '<a class="nav-link '.$active.'" href="'.URL.($item['url'] ?? '').'">'
					.'<div class="sb-nav-link-icon"><i class="'.htmlspecialchars((string)$item['icon']).'"></i></div>'
					.htmlspecialchars((string)$item['label']).'</a>';
				continue;
			}

			$expanded = $grupoAtivo === $gid ? 'true' : 'false';
			$show = $grupoAtivo === $gid ? 'show' : '';
			$current = $grupoAtivo === $gid ? 'active' : '';

			$subLinks = '';
			foreach ($items as $item) {
				$subActive = $menuAtivo === ($item['id'] ?? '') ? 'active' : '';
				$subLinks .= '<a class="nav-link '.$subActive.'" href="'.URL.($item['url'] ?? '').'">'
					.htmlspecialchars((string)$item['label']).'</a>';
			}

			$html .= View::render('master/menu/dropdown', [
				'label'    => (string)$grupo['label'],
				'icon'     => (string)$grupo['icon'],
				'name'     => $gid,
				'subLinks' => $subLinks,
				'current'  => $current,
				'expanded' => $expanded,
				'show'     => $show,
			]);
		}

		$html .= '</div>';
		return $html;
	}
}
