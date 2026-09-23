<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjCandidatoFormacao {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_candidato_formacao'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getByCandidatoTrilhaOrigem(int $idCandidato, int $idTrilha, string $origem): ?array {
		if (!self::tabelaExiste() || $idCandidato <= 0 || $idTrilha <= 0) {
			return null;
		}
		$stmt = (new Database('cj_candidato_formacao'))->select(
			'id_candidato = '.(int)$idCandidato
			.' AND id_trilha = '.(int)$idTrilha
			.' AND origem = "'.addslashes($origem).'"',
			null,
			'1'
		);
		$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
		return is_array($row) ? $row : null;
	}

	public static function excluirPorCandidatoTrilhaOrigem(int $idCandidato, int $idTrilha, string $origem): bool {
		if (!self::tabelaExiste() || $idCandidato <= 0 || $idTrilha <= 0) {
			return false;
		}
		return (new Database('cj_candidato_formacao'))->delete(
			'id_candidato = '.(int)$idCandidato
			.' AND id_trilha = '.(int)$idTrilha
			.' AND origem = "'.addslashes($origem).'"'
		);
	}

	public static function getByCandidatoCursoOrigem(int $idCandidato, int $idCursoLms, string $origem): ?array {
		if (!self::tabelaExiste() || $idCandidato <= 0) {
			return null;
		}
		$stmt = (new Database('cj_candidato_formacao'))->select(
			'id_candidato = '.(int)$idCandidato
			.' AND id_curso_lms = '.(int)$idCursoLms
			.' AND origem = "'.addslashes($origem).'"',
			null,
			'1'
		);
		$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
		return is_array($row) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarPorCandidato(int $idCandidato): array {
		if (!self::tabelaExiste() || $idCandidato <= 0) {
			return [];
		}
		$stmt = (new Database('cj_candidato_formacao'))->select(
			'id_candidato = '.(int)$idCandidato,
			'concluido_em DESC, id DESC'
		);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public static function inserir(array $dados): ?int {
		if (!self::tabelaExiste()) {
			return null;
		}
		$id = (new Database('cj_candidato_formacao'))->insert($dados);
		return $id ? (int)$id : null;
	}

	public static function atualizar(int $id, array $dados): bool {
		if (!self::tabelaExiste() || $id <= 0) {
			return false;
		}
		return (new Database('cj_candidato_formacao'))->update('id = '.(int)$id, $dados);
	}

	public static function temSeloCertificado(int $idCandidato): bool {
		if (!self::tabelaExiste() || $idCandidato <= 0) {
			return false;
		}
		$stmt = (new Database('cj_candidato_formacao'))->select(
			'id_candidato = '.(int)$idCandidato
			.' AND selo_certificado = 1 AND status = "concluido"'
			.' AND origem IN ("manual","matricula_auto")',
			null,
			'1',
			'id'
		);
		return $stmt && $stmt->fetch(PDO::FETCH_ASSOC);
	}
}
