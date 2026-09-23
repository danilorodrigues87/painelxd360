<?php

namespace App\Common;

/** Catálogo de módulos/permissões do Painel Master XD360. */
class MasterModules {

	private static $catalog = [
		'clientes'      => 'Clientes',
		'planos'        => 'Planos',
		'assinaturas'   => 'Assinaturas',
		'contrato_saas' => 'Contrato SaaS',
		'dados_xd360'   => 'Dados jurídicos XD360',
		'chamados'      => 'Chamados',
		'documentacao'  => 'Documentação',
		'usuarios'      => 'Usuários Master',
	];

	public static function getCatalog(): array {
		return self::$catalog;
	}

	public static function getSlugs(): array {
		return array_keys(self::$catalog);
	}

	public static function slugParaLabel(string $slug): ?string {
		return self::$catalog[$slug] ?? null;
	}

	public static function campoPermissao(string $slug): string {
		return 'perm_master_'.preg_replace('/[^a-z0-9_]/', '', $slug);
	}

	public static function slugsFromPost(array $post): array {
		$out = [];
		foreach (self::getSlugs() as $slug) {
			$campo = self::campoPermissao($slug);
			if (!empty($post[$campo])) {
				$out[] = $slug;
			}
		}
		return array_values(array_unique($out));
	}

	public static function htmlCheckboxes(array $slugsMarcados): string {
		$html = '';
		foreach (self::$catalog as $slug => $label) {
			$checked = in_array($slug, $slugsMarcados, true) ? ' checked' : '';
			$campo = self::campoPermissao($slug);
			$html .= '<div class="col-md-4 col-sm-6"><div class="form-check">'
				.'<input class="form-check-input chk-master-perm" type="checkbox" id="'.$campo.'" name="'.$campo.'" value="1"'.$checked.'>'
				.'<label class="form-check-label" for="'.$campo.'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</label>'
				.'</div></div>';
		}
		return $html;
	}
}
