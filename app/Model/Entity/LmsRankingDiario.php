<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

/**
 * Snapshot diário de ranking (escola / global) para conquistas de posição × dias.
 */
class LmsRankingDiario {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'lms_ranking_diario'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/**
	 * Grava (ou atualiza) posição do aluno no dia.
	 * @param 'escola'|'global' $scope
	 */
	public static function upsert(string $data, string $scope, int $idAdmin, int $idAluno, int $posicao, int $xp): void {
		if (!self::tabelaExiste() || $idAluno <= 0 || $posicao <= 0) {
			return;
		}
		$scope = $scope === 'global' ? 'global' : 'escola';
		$idAdmin = $scope === 'global' ? 0 : max(0, $idAdmin);
		$db = new Database('lms_ranking_diario');
		$where = 'data = "'.addslashes($data).'" AND scope = "'.$scope.'"'
			.' AND id_admin = '.(int)$idAdmin.' AND id_aluno = '.(int)$idAluno;
		$exists = $db->select($where, null, '1', 'id')->fetch(PDO::FETCH_ASSOC);
		if ($exists) {
			$db->update($where, ['posicao' => $posicao, 'xp' => $xp]);
		} else {
			$db->insert([
				'data' => $data,
				'scope' => $scope,
				'id_admin' => $idAdmin,
				'id_aluno' => $idAluno,
				'posicao' => $posicao,
				'xp' => $xp,
			]);
		}
	}

	/**
	 * Dias consecutivos (a partir do snapshot mais recente) na posição exata.
	 */
	public static function diasConsecutivosNaPosicao(int $idAluno, string $scope, int $posicao, int $idAdmin = 0): int {
		if (!self::tabelaExiste() || $idAluno <= 0 || $posicao <= 0) {
			return 0;
		}
		$scope = $scope === 'global' ? 'global' : 'escola';
		$idAdmin = $scope === 'global' ? 0 : max(0, $idAdmin);
		try {
			$rows = (new Database('lms_ranking_diario'))->select(
				'id_aluno = '.(int)$idAluno
				.' AND scope = "'.$scope.'"'
				.' AND id_admin = '.(int)$idAdmin,
				'data DESC',
				'400',
				'data, posicao'
			)->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Throwable $e) {
			return 0;
		}
		if (!$rows) {
			return 0;
		}

		$dias = 0;
		$esperado = null;
		foreach ($rows as $r) {
			$d = (string)($r['data'] ?? '');
			$p = (int)($r['posicao'] ?? 0);
			if ($d === '' || $p !== $posicao) {
				break;
			}
			if ($esperado === null) {
				$esperado = $d;
			} elseif ($d !== $esperado) {
				break;
			}
			$dias++;
			$esperado = date('Y-m-d', strtotime($esperado.' -1 day'));
		}
		return $dias;
	}
}
