<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class PlanosCurso {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'planos_cursos'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return int[] */
	public static function idsPorPlano(int $planId): array {
		if (!self::tabelaExiste() || $planId <= 0) {
			return [];
		}
		$sql = 'SELECT curso_id FROM planos_cursos WHERE plan_id = '.(int)$planId.' ORDER BY ordem ASC, id ASC';
		$rows = (new Database('planos_cursos'))->execute($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		$out = [];
		foreach ($rows as $r) {
			$id = (int)($r['curso_id'] ?? 0);
			if ($id > 0) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** @param int[] $cursoIds */
	public static function syncPlano(int $planId, array $cursoIds): void {
		if (!self::tabelaExiste() || $planId <= 0) {
			return;
		}
		$db = new Database('planos_cursos');
		$db->execute('DELETE FROM planos_cursos WHERE plan_id = '.(int)$planId);
		$ordem = 0;
		foreach ($cursoIds as $cid) {
			$cid = (int)$cid;
			if ($cid <= 0) {
				continue;
			}
			$db->insert([
				'plan_id'  => $planId,
				'curso_id' => $cid,
				'ordem'    => $ordem++,
			]);
		}
	}
}
