<?php

namespace App\Common;

/**
 * Módulos do painel do cliente (menu + permissões de equipe).
 * Produtos licenciados ficam em ProductModules / modulos_liberados do tenant.
 */
class SystemModules {

	private static $catalog = [
		'dados_cliente' => 'Dados da empresa',
		'assinatura'    => 'Assinatura',
		'produtos'      => 'Meus Produtos',
		'suporte'       => 'Suporte',
	];

	private static $labelAliases = [];

	public static function getCatalog(): array {
		return self::$catalog;
	}

	public static function getSlugs(): array {
		return array_keys(self::$catalog);
	}

	public static function getPermissions(): array {
		return array_values(self::$catalog);
	}

	public static function slugParaLabel(string $slug): ?string {
		return self::$catalog[$slug] ?? null;
	}

	public static function labelParaSlug(string $label): ?string {
		$label = self::normalizarLabel($label);
		if ($label === null) {
			return null;
		}
		$slug = array_search($label, self::$catalog, true);
		return $slug !== false ? $slug : null;
	}

	public static function campoPermissao(?string $label): string {
		$slug = self::labelParaSlug((string)$label);
		if ($slug !== null) {
			return 'perm_'.$slug;
		}
		$safe = preg_replace('/[^a-z0-9_]+/i', '_', (string)$label);
		return 'perm_'.strtolower((string)$safe);
	}

	public static function normalizarLabel(?string $label): ?string {
		if ($label === null || $label === '' || $label === '0') {
			return null;
		}
		$label = (string)$label;
		if (isset(self::$labelAliases[$label])) {
			$label = self::$labelAliases[$label];
		}
		if (!in_array($label, self::$catalog, true)) {
			return null;
		}
		return $label;
	}

	public static function labelsParaSlugs(array $labels): array {
		$slugs = [];
		foreach ($labels as $label) {
			$slug = self::labelParaSlug((string)$label);
			if ($slug !== null) {
				$slugs[] = $slug;
			}
		}
		return array_values(array_unique($slugs));
	}

	public static function slugsParaLabels(array $slugs): array {
		$labels = [];
		foreach ($slugs as $slug) {
			$label = self::slugParaLabel((string)$slug);
			if ($label !== null) {
				$labels[] = $label;
			}
		}
		return array_values(array_unique($labels));
	}

	public static function getModules(): array {
		$base = defined('URL') ? rtrim((string)URL, '/') : '';
		return [
			'Dashboard' => [
				'label' => 'Dashboard',
				'link'  => $base.'/painel',
				'icon'  => 'fas fa-tachometer-alt',
			],
			'produtos' => [
				'label' => 'Meus Produtos',
				'link'  => $base.'/painel/produtos',
				'icon'  => 'fas fa-th-large',
			],
			'Financeiro' => [
				'label' => 'Financeiro',
				'icon'  => 'fa-solid fa-coins',
				'subsections' => [
					'name'  => 'Layouts-Financeiro',
					'icon'  => 'fas fa-caret-down',
					'items' => [
						[
							'label' => 'Assinatura',
							'link'  => $base.'/painel/assinatura',
						],
					],
				],
			],
			'config' => [
				'label' => 'Configurações',
				'icon'  => 'fas fa-cog',
				'subsections' => [
					'name'  => 'Layouts-config',
					'icon'  => 'fas fa-caret-down',
					'items' => [
						[
							'label' => 'Dados da empresa',
							'link'  => $base.'/painel/config/empresa',
						],
					],
				],
			],
			'Suporte' => [
				'label' => 'Suporte',
				'link'  => $base.'/painel/suporte',
				'icon'  => 'fas fa-headset',
			],
			'Ajuda' => [
				'label' => 'Ajuda',
				'link'  => $base.'/painel/ajuda',
				'icon'  => 'fas fa-circle-question',
			],
		];
	}

	public static function htmlCheckboxes(array $slugsMarcados): string {
		$html = '';
		foreach (self::$catalog as $slug => $label) {
			$checked = in_array($slug, $slugsMarcados, true) ? ' checked' : '';
			$campo = self::campoPermissao($label);
			$html .= '<div class="col-md-4 col-sm-6"><div class="form-check">'
				.'<input class="form-check-input chk-mod" type="checkbox" id="'.$campo.'" name="'.$campo.'" value="1"'.$checked.'>'
				.'<label class="form-check-label" for="'.$campo.'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</label>'
				.'</div></div>';
		}
		return $html;
	}
}
