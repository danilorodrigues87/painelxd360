<?php

namespace App\Common\Helpers;

use App\Common\ProductModules;
use App\Common\SystemModules;
use App\Model\Entity\ClientesAssinantes;

class ModuleGateHelper {

	private static $cacheEscola = [];

	/** Slugs do menu/permissões do painel do cliente (SystemModules). */
	public static function getSlugsEscola(int $idAdmin): array {
		return SystemModules::getSlugs();
	}

	/** Slugs dos produtos HTML licenciados ao tenant (ProductModules). */
	public static function getSlugsProdutos(int $idAdmin): array {
		if ($idAdmin <= 0) {
			return ProductModules::getSlugs();
		}

		$cacheKey = 'prod_'.$idAdmin;
		if (isset(self::$cacheEscola[$cacheKey])) {
			return self::$cacheEscola[$cacheKey];
		}

		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola) {
			self::$cacheEscola[$cacheKey] = [];
			return [];
		}

		$raw = $escola->modulos_liberados ?? null;
		if ($raw === null || $raw === '') {
			self::$cacheEscola[$cacheKey] = ProductModules::getSlugs();
			return self::$cacheEscola[$cacheKey];
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			self::$cacheEscola[$cacheKey] = ProductModules::getSlugs();
			return self::$cacheEscola[$cacheKey];
		}
		if ($decoded === []) {
			self::$cacheEscola[$cacheKey] = [];
			return [];
		}

		$slugsMap = array_flip(ProductModules::getSlugs());
		$filtrados = [];
		foreach ($decoded as $slug) {
			$slug = (string)$slug;
			if (isset($slugsMap[$slug])) {
				$filtrados[] = $slug;
			}
		}

		self::$cacheEscola[$cacheKey] = !empty($filtrados) ? $filtrados : [];
		return self::$cacheEscola[$cacheKey];
	}

	/**
	 * Plano com `ead` também libera submódulos `conquistas_ead` e `vitrine` (checkboxes separados no usuário).
	 * @param string[] $slugs
	 * @return string[]
	 */
	private static function expandirSlugsDependentes(array $slugs): array {
		if (in_array('ead', $slugs, true) && !in_array('conquistas_ead', $slugs, true)) {
			$slugs[] = 'conquistas_ead';
		}
		if (in_array('ead', $slugs, true) && !in_array('vitrine', $slugs, true)) {
			$slugs[] = 'vitrine';
		}
		if (in_array('diario', $slugs, true) && !in_array('agenda_relatorio', $slugs, true)) {
			$slugs[] = 'agenda_relatorio';
		}
		return array_values(array_unique($slugs));
	}

	public static function getModulosEscola(int $idAdmin): array {
		return SystemModules::slugsParaLabels(self::getSlugsEscola($idAdmin));
	}

	/**
	 * Labels exibidos no checklist de funcionários.
	 * Exclui itens que só o Diretor usa via menu automático (não fazem sentido para equipe).
	 */
	public static function getModulosDisponiveisParaEscola(int $idAdmin): array {
		$labels = self::getModulosEscola($idAdmin);
		$somenteDiretor = ['Dados da escola', 'Assinatura', 'Assistente IA'];
		return array_values(array_filter($labels, static function ($l) use ($somenteDiretor) {
			return !in_array($l, $somenteDiretor, true);
		}));
	}

	public static function normalizarAcessoUsuario(array $acessoUsuario): array {
		$labels = [];
		foreach ($acessoUsuario as $item) {
			if ($item === '' || $item === 0 || $item === '0') {
				continue;
			}
			$label = SystemModules::normalizarLabel((string)$item);
			if ($label !== null) {
				$labels[] = $label;
			}
		}
		return array_values(array_unique($labels));
	}

	public static function getModulosEfetivos(int $idAdmin, array $acessoUsuario): array {
		$escola = self::getModulosEscola($idAdmin);
		$usuario = self::normalizarAcessoUsuario($acessoUsuario);

		if (empty($escola)) {
			return $usuario;
		}

		return array_values(array_intersect($escola, $usuario));
	}

	public static function podeAcessar(string $label, int $idAdmin, array $acessoUsuario): bool {
		$label = SystemModules::normalizarLabel($label);
		if ($label === null) {
			return false;
		}
		return in_array($label, self::getModulosEfetivos($idAdmin, $acessoUsuario), true);
	}

	public static function sanitizarAcesso(int $idAdmin, array $acessoUsuario): array {
		$efetivos = self::getModulosEfetivos($idAdmin, $acessoUsuario);
		return !empty($efetivos) ? $efetivos : [''];
	}

	public static function escolaTemTodosModulos(int $idAdmin): bool {
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola) {
			return true;
		}
		$raw = $escola->modulos_liberados ?? null;
		return $raw === null || $raw === '';
	}

	public static function limparCache(?int $idAdmin = null): void {
		if ($idAdmin === null) {
			self::$cacheEscola = [];
			return;
		}
		unset(self::$cacheEscola[$idAdmin]);
	}

	/**
	 * Após mudar plano/módulos da escola: alinha o checklist do Diretor aos módulos liberados.
	 * Sem isso, getModulosEfetivos (escola ∩ usuario.acesso) esconde módulos novos.
	 */
	public static function sincronizarAcessoDiretores(int $idAdmin): int {
		if ($idAdmin <= 0) {
			return 0;
		}
		self::limparCache($idAdmin);
		$labels = self::getModulosEscola($idAdmin);
		if (empty($labels)) {
			return 0;
		}
		$json = json_encode(array_values($labels), JSON_UNESCAPED_UNICODE);
		$stmt = \App\Model\Entity\User::getUser(
			'id_admin = '.(int)$idAdmin.' AND nivel = "Diretor"',
			null,
			null,
			'id'
		);
		$n = 0;
		$db = new \App\Model\Db\Database('usuarios');
		while ($u = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$id = (int)($u['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$db->update('id = '.$id, ['acesso' => $json]);
			$n++;
		}
		return $n;
	}

}
