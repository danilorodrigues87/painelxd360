<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class LmsEscolaCursoCti {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_escola_cursos_cti'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function ativaParaEscolaCurso(int $idAdmin, int $cursoId): ?array {
		if (!self::tabelaExiste()) {
			return null;
		}
		$row = (new Database('lms_escola_cursos_cti'))->select(
			'id_admin = '.(int)$idAdmin.' AND curso_id = '.(int)$cursoId.' AND ativo = 1',
			null,
			1
		)->fetch(\PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	public static function contarAtivosEscola(int $idAdmin): int {
		if (!self::tabelaExiste()) {
			return 0;
		}
		$row = (new Database('lms_escola_cursos_cti'))->select(
			'id_admin = '.(int)$idAdmin.' AND ativo = 1',
			null,
			null,
			'COUNT(*) AS qtd'
		)->fetch(\PDO::FETCH_ASSOC);
		return (int)($row['qtd'] ?? 0);
	}

	/** @return array<int, array<string, mixed>> */
	public static function listarAtivosEscola(int $idAdmin): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$sql = 'SELECT ec.*, c.titulo, c.slug, c.publicado, c.carga_h, c.cover_url
			FROM lms_escola_cursos_cti ec
			INNER JOIN lms_cursos c ON c.id = ec.curso_id
			WHERE ec.id_admin = '.(int)$idAdmin.' AND ec.ativo = 1
			ORDER BY ec.id ASC';
		return (new Database('lms_escola_cursos_cti'))->execute($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	public static function upsert(int $idAdmin, int $cursoId, int $planId): void {
		if (!self::tabelaExiste()) {
			return;
		}
		$db = new Database('lms_escola_cursos_cti');
		$ex = $db->select(
			'id_admin = '.(int)$idAdmin.' AND curso_id = '.(int)$cursoId,
			null,
			1
		)->fetch(\PDO::FETCH_ASSOC);

		if ($ex) {
			$db->update('id = '.(int)$ex['id'], [
				'ativo'    => 1,
				'plan_id'  => $planId > 0 ? $planId : null,
			]);
			return;
		}

		$db->insert([
			'id_admin'  => $idAdmin,
			'curso_id'  => $cursoId,
			'plan_id'   => $planId > 0 ? $planId : null,
			'ativo'     => 1,
		]);
	}

	public static function desativarForaLista(int $idAdmin, array $cursoIdsAtivos): void {
		if (!self::tabelaExiste()) {
			return;
		}
		$db = new Database('lms_escola_cursos_cti');
		if ($cursoIdsAtivos === []) {
			$db->execute('UPDATE lms_escola_cursos_cti SET ativo = 0 WHERE id_admin = '.(int)$idAdmin);
			return;
		}
		$ids = implode(',', array_map('intval', $cursoIdsAtivos));
		$db->execute(
			'UPDATE lms_escola_cursos_cti SET ativo = 0 WHERE id_admin = '.(int)$idAdmin
			.' AND curso_id NOT IN ('.$ids.')'
		);
	}
}
