<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjAnuncioEvento {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_anuncio_eventos'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function registrar(
		int $anuncioId,
		string $tipo,
		?string $visitorId = null,
		?string $slot = null,
		?string $uf = null,
		?int $cidadeId = null
	): bool {
		if (!self::tabelaExiste() || $anuncioId <= 0) {
			return false;
		}
		if (!in_array($tipo, ['impressao', 'clique'], true)) {
			return false;
		}
		$visitorId = trim((string)$visitorId);
		if (strlen($visitorId) > 64) {
			$visitorId = substr($visitorId, 0, 64);
		}
		$uf = strtoupper(trim((string)$uf));
		if ($uf !== '' && strlen($uf) !== 2) {
			$uf = '';
		}
		return (bool)(new Database('cj_anuncio_eventos'))->insert([
			'anuncio_id' => $anuncioId,
			'tipo'       => $tipo,
			'visitor_id' => $visitorId !== '' ? $visitorId : null,
			'slot'       => $slot !== null && $slot !== '' ? substr($slot, 0, 40) : null,
			'uf'         => $uf !== '' ? $uf : null,
			'cidade_id'  => ($cidadeId ?? 0) > 0 ? $cidadeId : null,
		]);
	}

	/** @return array{impressoes:int,cliques:int} */
	public static function resumoPorAnuncio(int $anuncioId): array {
		if (!self::tabelaExiste() || $anuncioId <= 0) {
			return ['impressoes' => 0, 'cliques' => 0];
		}
		$sql = 'SELECT tipo, COUNT(*) AS q FROM cj_anuncio_eventos '
			.'WHERE anuncio_id = '.(int)$anuncioId.' GROUP BY tipo';
		$stmt = (new Database())->execute($sql);
		$out = ['impressoes' => 0, 'cliques' => 0];
		if (!$stmt) {
			return $out;
		}
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			if (($row['tipo'] ?? '') === 'impressao') {
				$out['impressoes'] = (int)($row['q'] ?? 0);
			} elseif (($row['tipo'] ?? '') === 'clique') {
				$out['cliques'] = (int)($row['q'] ?? 0);
			}
		}
		return $out;
	}

	/** @return array<int,array{impressoes:int,cliques:int}> */
	public static function resumoPorAnuncios(array $ids): array {
		$out = [];
		foreach ($ids as $id) {
			$out[(int)$id] = ['impressoes' => 0, 'cliques' => 0];
		}
		if (!self::tabelaExiste() || empty($ids)) {
			return $out;
		}
		$ids = array_values(array_filter(array_map('intval', $ids), static fn($id) => $id > 0));
		if (empty($ids)) {
			return $out;
		}
		$sql = 'SELECT anuncio_id, tipo, COUNT(*) AS q FROM cj_anuncio_eventos '
			.'WHERE anuncio_id IN ('.implode(',', $ids).') GROUP BY anuncio_id, tipo';
		$stmt = (new Database())->execute($sql);
		if (!$stmt) {
			return $out;
		}
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$aid = (int)($row['anuncio_id'] ?? 0);
			if (!isset($out[$aid])) {
				continue;
			}
			if (($row['tipo'] ?? '') === 'impressao') {
				$out[$aid]['impressoes'] = (int)($row['q'] ?? 0);
			} elseif (($row['tipo'] ?? '') === 'clique') {
				$out[$aid]['cliques'] = (int)($row['q'] ?? 0);
			}
		}
		return $out;
	}
}
