<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjAnalytics {

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_analytics_visitas'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function registrarPageview(string $visitorKey, string $path, ?string $referrer = null): bool {
		if (!self::tabelasExistem() || !self::visitorKeyValido($visitorKey)) {
			return false;
		}
		$path = self::normalizarPath($path);
		$referrer = self::normalizarReferrer($referrer);
		$isNovo = 0;

		$stmt = (new Database())->execute(
			'SELECT visitor_key FROM cj_analytics_visitantes WHERE visitor_key = "'
			.addslashes($visitorKey).'" LIMIT 1'
		);
		$existe = $stmt && $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$existe) {
			$isNovo = 1;
			(new Database('cj_analytics_visitantes'))->insert([
				'visitor_key'    => $visitorKey,
				'primeira_visita'=> date('Y-m-d H:i:s'),
				'ultima_visita'  => date('Y-m-d H:i:s'),
				'total_visitas'  => 1,
			]);
		} else {
			(new Database())->execute(
				'UPDATE cj_analytics_visitantes SET ultima_visita = NOW(), total_visitas = total_visitas + 1 '
				.'WHERE visitor_key = "'.addslashes($visitorKey).'"'
			);
		}

		(new Database('cj_analytics_visitas'))->insert([
			'visitor_key' => $visitorKey,
			'path'        => $path,
			'referrer'    => $referrer,
			'is_novo'     => $isNovo,
		]);
		return true;
	}

	public static function registrarShare(string $plataforma, string $path, ?string $slug, ?string $titulo): bool {
		if (!self::tabelasExistem()) {
			return false;
		}
		$allowed = ['whatsapp', 'facebook', 'linkedin', 'twitter', 'copy'];
		if (!in_array($plataforma, $allowed, true)) {
			return false;
		}
		(new Database('cj_analytics_compartilhamentos'))->insert([
			'plataforma' => $plataforma,
			'path'       => self::normalizarPath($path),
			'slug'       => $slug !== null && $slug !== '' ? mb_substr($slug, 0, 220) : null,
			'titulo_ref' => $titulo !== null && $titulo !== '' ? mb_substr($titulo, 0, 220) : null,
		]);
		return true;
	}

	public static function contarVisitas(string $de, string $ate): int {
		if (!self::tabelasExistem()) {
			return 0;
		}
		return self::scalar(
			'SELECT COUNT(*) FROM cj_analytics_visitas WHERE '
			.self::wherePeriodo('created_at', $de, $ate)
		);
	}

	public static function contarVisitantesUnicos(string $de, string $ate): int {
		if (!self::tabelasExistem()) {
			return 0;
		}
		return self::scalar(
			'SELECT COUNT(DISTINCT visitor_key) FROM cj_analytics_visitas WHERE '
			.self::wherePeriodo('created_at', $de, $ate)
		);
	}

	public static function contarNovosVisitantes(string $de, string $ate): int {
		if (!self::tabelasExistem()) {
			return 0;
		}
		return self::scalar(
			'SELECT COUNT(*) FROM cj_analytics_visitantes WHERE '
			.self::wherePeriodo('primeira_visita', $de, $ate)
		);
	}

	public static function contarCompartilhamentos(string $de, string $ate): int {
		if (!self::tabelasExistem()) {
			return 0;
		}
		return self::scalar(
			'SELECT COUNT(*) FROM cj_analytics_compartilhamentos WHERE '
			.self::wherePeriodo('created_at', $de, $ate)
		);
	}

	/** @return array<int,array{dia:string,visitas:int,novos_visitantes:int,cadastros:int,shares:int}> */
	public static function serieDiaria(string $de, string $ate): array {
		if (!self::tabelasExistem()) {
			return [];
		}
		$de = self::validarData($de) ?: date('Y-m-01');
		$ate = self::validarData($ate) ?: date('Y-m-d');

		$visitas = self::mapPorDia(
			'SELECT DATE(created_at) AS dia, COUNT(*) AS q FROM cj_analytics_visitas WHERE '
			.self::wherePeriodo('created_at', $de, $ate).' GROUP BY DATE(created_at)'
		);
		$novos = self::mapPorDia(
			'SELECT DATE(primeira_visita) AS dia, COUNT(*) AS q FROM cj_analytics_visitantes WHERE '
			.self::wherePeriodo('primeira_visita', $de, $ate).' GROUP BY DATE(primeira_visita)'
		);
		$shares = self::mapPorDia(
			'SELECT DATE(created_at) AS dia, COUNT(*) AS q FROM cj_analytics_compartilhamentos WHERE '
			.self::wherePeriodo('created_at', $de, $ate).' GROUP BY DATE(created_at)'
		);
		$cadastros = [];
		if (CjCandidato::tabelaExiste()) {
			$cadastros = self::mapPorDia(
				'SELECT DATE(created_at) AS dia, COUNT(*) AS q FROM cj_candidatos WHERE '
				.self::wherePeriodo('created_at', $de, $ate).' GROUP BY DATE(created_at)'
			);
		}

		$out = [];
		$cur = strtotime($de);
		$end = strtotime($ate);
		while ($cur !== false && $cur <= $end) {
			$dia = date('Y-m-d', $cur);
			$out[] = [
				'dia'              => $dia,
				'diaBr'            => date('d/m', $cur),
				'visitas'          => (int)($visitas[$dia] ?? 0),
				'novos_visitantes' => (int)($novos[$dia] ?? 0),
				'cadastros'        => (int)($cadastros[$dia] ?? 0),
				'shares'           => (int)($shares[$dia] ?? 0),
			];
			$cur = strtotime('+1 day', $cur);
		}
		return $out;
	}

	/** @return array<int,array{path:string,visitas:int}> */
	public static function topPaginas(string $de, string $ate, int $limit = 10): array {
		if (!self::tabelasExistem()) {
			return [];
		}
		$limit = max(1, min(20, $limit));
		$stmt = (new Database())->execute(
			'SELECT CASE '
			.'WHEN LOCATE("?", path) > 0 THEN TRIM(TRAILING "/" FROM SUBSTRING_INDEX(path, "?", 1)) '
			.'ELSE TRIM(TRAILING "/" FROM path) '
			.'END AS path, COUNT(*) AS visitas FROM cj_analytics_visitas WHERE '
			.self::wherePeriodo('created_at', $de, $ate)
			.' GROUP BY path ORDER BY visitas DESC LIMIT '.$limit
		);
		$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
		$out = [];
		foreach ($rows as $r) {
			$path = self::normalizarPath((string)($r['path'] ?? '/'));
			$out[] = [
				'path'    => $path,
				'visitas' => (int)($r['visitas'] ?? 0),
			];
		}
		return $out;
	}

	/** @return array<int,array{plataforma:string,label:string,qtd:int}> */
	public static function sharesPorPlataforma(string $de, string $ate): array {
		if (!self::tabelasExistem()) {
			return [];
		}
		$labels = [
			'whatsapp' => 'WhatsApp',
			'facebook' => 'Facebook',
			'linkedin' => 'LinkedIn',
			'twitter'  => 'X (Twitter)',
			'copy'     => 'Copiar link',
		];
		$stmt = (new Database())->execute(
			'SELECT plataforma, COUNT(*) AS qtd FROM cj_analytics_compartilhamentos WHERE '
			.self::wherePeriodo('created_at', $de, $ate)
			.' GROUP BY plataforma ORDER BY qtd DESC'
		);
		$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
		$out = [];
		foreach ($rows as $r) {
			$p = (string)($r['plataforma'] ?? '');
			$out[] = [
				'plataforma' => $p,
				'label'      => $labels[$p] ?? $p,
				'qtd'        => (int)($r['qtd'] ?? 0),
			];
		}
		return $out;
	}

	public static function visitasPaginaCadastro(string $de, string $ate): int {
		if (!self::tabelasExistem()) {
			return 0;
		}
		return self::scalar(
			'SELECT COUNT(*) FROM cj_analytics_visitas WHERE path IN ("/cadastro","/cadastro/empresa") AND '
			.self::wherePeriodo('created_at', $de, $ate)
		);
	}

	private static function visitorKeyValido(string $key): bool {
		return (bool)preg_match('/^[a-f0-9-]{36}$/i', $key);
	}

	private static function normalizarPath(string $path): string {
		$path = trim($path);
		$qPos = strpos($path, '?');
		if ($qPos !== false) {
			$path = substr($path, 0, $qPos);
		}
		$hPos = strpos($path, '#');
		if ($hPos !== false) {
			$path = substr($path, 0, $hPos);
		}
		if ($path === '' || !str_starts_with($path, '/')) {
			$path = '/'.$path;
		}
		$path = preg_replace('#/+#', '/', $path) ?? '/';
		if ($path !== '/' && str_ends_with($path, '/')) {
			$path = rtrim($path, '/');
		}
		return mb_substr($path, 0, 255);
	}

	private static function normalizarReferrer(?string $ref): ?string {
		if ($ref === null || trim($ref) === '') {
			return null;
		}
		$ref = trim(strip_tags($ref));
		return mb_substr($ref, 0, 500);
	}

	private static function wherePeriodo(string $col, string $de, string $ate): string {
		$de = self::validarData($de) ?: date('Y-m-01');
		$ate = self::validarData($ate) ?: date('Y-m-d');
		return 'DATE('.$col.') >= "'.addslashes($de).'" AND DATE('.$col.') <= "'.addslashes($ate).'"';
	}

	private static function validarData(string $d): ?string {
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
	}

	/** @return array<string,int> */
	private static function mapPorDia(string $sql): array {
		$stmt = (new Database())->execute($sql);
		$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
		$map = [];
		foreach ($rows as $r) {
			$map[(string)($r['dia'] ?? '')] = (int)($r['q'] ?? 0);
		}
		return $map;
	}

	private static function scalar(string $sql): int {
		try {
			$stmt = (new Database())->execute($sql);
			$row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : false;
			return (int)($row[0] ?? 0);
		} catch (\Throwable $e) {
			return 0;
		}
	}
}
