<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjCandidatura {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_candidaturas'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getById(int $id): ?array {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$rows = self::queryLista(['id' => $id, 'limit' => 1]);
		return $rows[0] ?? null;
	}

	public static function getByVagaECandidato(int $idVaga, int $idCandidato): ?array {
		if (!self::tabelaExiste() || $idVaga <= 0 || $idCandidato <= 0) {
			return null;
		}
		$rows = self::queryLista([
			'id_vaga'      => $idVaga,
			'id_candidato' => $idCandidato,
			'limit'        => 1,
		]);
		return $rows[0] ?? null;
	}

	/**
	 * @param array{id?:int,id_vaga?:int,id_candidato?:int,limit?:int} $filtros
	 * @return array<int,array<string,mixed>>
	 */
	public static function queryLista(array $filtros = []): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$where = ['1=1'];
		if (!empty($filtros['id'])) {
			$where[] = 'c.id = '.(int)$filtros['id'];
		}
		if (!empty($filtros['id_vaga'])) {
			$where[] = 'c.id_vaga = '.(int)$filtros['id_vaga'];
		}
		if (!empty($filtros['id_candidato'])) {
			$where[] = 'c.id_candidato = '.(int)$filtros['id_candidato'];
		}
		if (!empty($filtros['id_empresa'])) {
			$where[] = 'v.id_empresa = '.(int)$filtros['id_empresa'];
		}
		if (!empty($filtros['status'])) {
			$where[] = 'c.status = "'.addslashes((string)$filtros['status']).'"';
		}
		$limit = max(1, min(100, (int)($filtros['limit'] ?? 50)));
		$sql = 'SELECT c.*, v.id_empresa, v.titulo AS vaga_titulo, v.slug AS vaga_slug, v.tipo_vaga, '
			.'v.status AS vaga_status, e.nome_fantasia AS empresa_nome, '
			.'cd.nome AS candidato_nome, cd.email AS candidato_email, cd.whatsapp AS candidato_whatsapp, '
			.'cd.nascimento AS candidato_nascimento, cd.resumo AS candidato_resumo, cd.disponibilidade AS candidato_disponibilidade, '
			.'cd.tipo AS candidato_tipo '
			.'FROM cj_candidaturas c '
			.'INNER JOIN cj_vagas v ON v.id = c.id_vaga '
			.'INNER JOIN cj_empresas e ON e.id = v.id_empresa '
			.'INNER JOIN cj_candidatos cd ON cd.id = c.id_candidato '
			.'WHERE '.implode(' AND ', $where).' '
			.'ORDER BY c.created_at DESC, c.id DESC '
			.'LIMIT '.$limit;
		$stmt = (new Database())->execute($sql);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public static function inserir(array $dados): ?int {
		if (!self::tabelaExiste()) {
			return null;
		}
		$id = (new Database('cj_candidaturas'))->insert($dados);
		return $id ? (int)$id : null;
	}

	public static function atualizar(int $id, array $dados): bool {
		if (!self::tabelaExiste() || $id <= 0) {
			return false;
		}
		return (new Database('cj_candidaturas'))->update('id = '.(int)$id, $dados);
	}
}
