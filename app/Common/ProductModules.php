<?php

namespace App\Common;

/**
 * Catálogo de produtos topapps (slugs estáveis → labels UI).
 * Usado em planos, modulos_liberados e tela "Meus Produtos".
 */
class ProductModules {

	/** @var array<string,string> slug => fixo|modular */
	private static $modo = [
		'doceflow'    => 'fixo',
		'fitpro'      => 'fixo',
		'financas'    => 'fixo',
		'estoqueiro'  => 'fixo',
		'salao'       => 'fixo',
		'nail'        => 'fixo',
		'odonto'      => 'fixo',
		'oficina'     => 'fixo',
		'bussinespro' => 'fixo',
	];

	/** @var array<string,string> slug => label */
	private static $catalog = [
		'doceflow'    => 'DoceFlow Pro',
		'fitpro'      => 'FitPro 360',
		'financas'    => 'Finança360 Pro',
		'estoqueiro'  => 'Estoqueiro',
		'salao'       => 'Salão Pro',
		'nail'        => 'Nail Studio',
		'odonto'      => 'OdontoGest',
		'oficina'     => 'OficinaPro 360',
		'bussinespro' => 'BusinessOS Pro',
	];

	/** @var array<string,string> slug => env key suffix */
	private static $pathEnv = [
		'doceflow'    => 'PRODUCT_DOCEFLOW',
		'fitpro'      => 'PRODUCT_FITPRO',
		'financas'    => 'PRODUCT_FINANCAS',
		'estoqueiro'  => 'PRODUCT_ESTOQUEIRO',
		'salao'       => 'PRODUCT_SALAO',
		'nail'        => 'PRODUCT_NAIL',
		'odonto'      => 'PRODUCT_ODONTO',
		'oficina'     => 'PRODUCT_OFICINA',
		'bussinespro' => 'PRODUCT_BUSSINESPRO',
	];

	public static function getCatalog(): array {
		return self::$catalog;
	}

	public static function modo(string $slug): string {
		return self::$modo[$slug] ?? 'fixo';
	}

	public static function ehModular(string $slug): bool {
		return self::modo($slug) === 'modular';
	}

	/** @return array<int,array{slug:string,label:string,modo:string}> */
	public static function listarComModo(): array {
		$out = [];
		foreach (self::$catalog as $slug => $label) {
			$out[] = [
				'slug'  => $slug,
				'label' => $label,
				'modo'  => self::modo($slug),
			];
		}
		return $out;
	}

	public static function getSlugs(): array {
		return array_keys(self::$catalog);
	}

	public static function slugParaLabel(string $slug): ?string {
		return self::$catalog[$slug] ?? null;
	}

	public static function labelParaSlug(string $label): ?string {
		foreach (self::$catalog as $slug => $lbl) {
			if ($lbl === $label) {
				return $slug;
			}
		}
		return null;
	}

	/** @param string[] $slugs */
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

	/** URL pública do HTML demo/produto. */
	public static function urlProduto(string $slug): ?string {
		$slug = trim($slug);
		if ($slug === '' || !isset(self::$pathEnv[$slug])) {
			return null;
		}
		$base = rtrim((string)getenv('TOPAPPS_BASE'), '/');
		$path = trim((string)getenv(self::$pathEnv[$slug]), '/');
		if ($base === '' || $path === '') {
			return null;
		}
		return $base.'/'.$path;
	}

	/** @return array<int,array{slug:string,label:string,url:?string}> */
	public static function listarComUrls(): array {
		$out = [];
		foreach (self::$catalog as $slug => $label) {
			$out[] = [
				'slug'  => $slug,
				'label' => $label,
				'url'   => self::urlProduto($slug),
			];
		}
		return $out;
	}

	public static function htmlCheckboxes(array $slugsMarcados): string {
		$html = '';
		foreach (self::$catalog as $slug => $label) {
			$checked = in_array($slug, $slugsMarcados, true) ? ' checked' : '';
			$campo = 'mod_'.preg_replace('/[^a-z0-9_]/', '', $slug);
			$html .= '<div class="col-md-4 col-sm-6"><div class="form-check">'
				.'<input class="form-check-input chk-mod-produto" type="checkbox" id="'.$campo.'" name="'.$campo.'" value="'.$slug.'"'.$checked.'>'
				.'<label class="form-check-label" for="'.$campo.'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</label>'
				.'</div></div>';
		}
		return $html;
	}
}
