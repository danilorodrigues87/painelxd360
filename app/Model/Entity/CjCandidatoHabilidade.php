<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjCandidatoHabilidade {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_candidato_habilidades'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return list<string> */
	public static function listarPorCandidato(int $idCandidato): array {
		if (!self::tabelaExiste() || $idCandidato <= 0) {
			return [];
		}
		$stmt = (new Database('cj_candidato_habilidades'))->select(
			'id_candidato = '.(int)$idCandidato,
			'habilidade ASC',
			null,
			'habilidade'
		);
		if (!$stmt) {
			return [];
		}
		return array_values(array_filter(array_map(
			static fn ($row) => trim((string)($row['habilidade'] ?? '')),
			$stmt->fetchAll(PDO::FETCH_ASSOC)
		)));
	}

	/** @param list<string> $habilidades */
	public static function sincronizar(int $idCandidato, array $habilidades): void {
		if (!self::tabelaExiste() || $idCandidato <= 0) {
			return;
		}
		(new Database('cj_candidato_habilidades'))->delete('id_candidato = '.(int)$idCandidato);
		$seen = [];
		foreach ($habilidades as $hab) {
			$hab = trim((string)$hab);
			if ($hab === '' || mb_strlen($hab) > 80) {
				continue;
			}
			$key = mb_strtolower($hab);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			(new Database('cj_candidato_habilidades'))->insert([
				'id_candidato' => $idCandidato,
				'habilidade'   => $hab,
			]);
		}
	}
}
