<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjCandidato {

	public $id;
	public $id_usuario;
	public $id_admin;
	public $tipo = 'externo';
	public $cadastrado_por_usuario_id;
	public $nome;
	public $email;
	public $whatsapp;
	public $nascimento;
	public $responsavel_nome;
	public $responsavel_consentimento_em;
	public $cidade_id;
	public $bairro;
	public $uf;
	public $resumo;
	public $status = 'ativo';
	public $crm_lead_id;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_candidatos'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getById(int $id): ?self {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$row = self::select('c.id = '.(int)$id, null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public static function getByIdEnriched(int $id): ?array {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$sql = 'SELECT c.*, ci.nome AS cidade_nome FROM cj_candidatos c '
			.'LEFT JOIN cidades ci ON ci.id = c.cidade_id '
			.'WHERE c.id = '.(int)$id.' LIMIT 1';
		$stmt = (new Database())->execute($sql);
		$row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
		if (!is_array($row)) {
			return null;
		}
		if (!empty($row['cidade_id'])) {
			$loc = \App\Common\Helpers\ConectEnderecoHelper::localPorCidadeId((int)$row['cidade_id']);
			if ($loc['uf'] !== '' && empty($row['uf'])) {
				$row['uf'] = $loc['uf'];
			}
			if (!empty($loc['estadoId'])) {
				$row['estado_id'] = $loc['estadoId'];
			}
			if ($loc['cidadeNome'] !== '') {
				$row['cidade_nome'] = $loc['cidadeNome'];
			}
		}
		return $row;
	}

	public static function getByUsuarioId(int $idUsuario): ?self {
		if (!self::tabelaExiste() || $idUsuario <= 0) {
			return null;
		}
		$row = self::select('c.id_usuario = '.(int)$idUsuario, null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getByEmail(string $email): ?self {
		$email = strtolower(trim($email));
		if (!self::tabelaExiste() || $email === '') {
			return null;
		}
		$row = self::select('LOWER(c.email) = "'.addslashes($email).'"', null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function select(?string $where = null, ?string $order = null, ?string $limit = null) {
		return (new Database('cj_candidatos c'))->select($where, $order, $limit, 'c.*');
	}

	public static function inserir(array $dados): ?int {
		if (!self::tabelaExiste()) {
			return null;
		}
		$id = (new Database('cj_candidatos'))->insert($dados);
		return $id ? (int)$id : null;
	}

	public static function atualizar(int $id, array $dados): bool {
		if (!self::tabelaExiste() || $id <= 0) {
			return false;
		}
		$dados = \App\Common\Helpers\ConectSchemaHelper::filtrar('cj_candidatos', $dados);
		if ($dados === []) {
			return true;
		}
		return (new Database('cj_candidatos'))->update('id = '.(int)$id, $dados);
	}

	public static function listarPorEscola(int $idAdmin, int $limit = 50): array {
		if (!self::tabelaExiste() || $idAdmin <= 0) {
			return [];
		}
		$stmt = self::select('c.id_admin = '.(int)$idAdmin, 'c.created_at DESC', (string)$limit);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	/**
	 * Banco de talentos para empresas aprovadas (busca global).
	 * @return array<int,array<string,mixed>>
	 */
	public static function buscarParaEmpresa(array $filtros = [], int $limit = 50): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$where = ['c.status = "ativo"'];
		if (!empty($filtros['cidade_id'])) {
			$where[] = 'c.cidade_id = '.(int)$filtros['cidade_id'];
		}
		if (!empty($filtros['uf'])) {
			$where[] = 'c.uf = "'.addslashes(strtoupper(substr((string)$filtros['uf'], 0, 2))).'"';
		}
		if (!empty($filtros['habilidade'])) {
			$hab = addslashes(trim((string)$filtros['habilidade']));
			$where[] = 'EXISTS (SELECT 1 FROM cj_candidato_habilidades h WHERE h.id_candidato = c.id AND h.habilidade LIKE "%'.$hab.'%")';
		}
		if (!empty($filtros['q'])) {
			$q = addslashes(trim((string)$filtros['q']));
			$where[] = '(c.nome LIKE "%'.$q.'%" OR c.resumo LIKE "%'.$q.'%" OR c.email LIKE "%'.$q.'%")';
		}
		$lim = max(1, min(100, $limit));
		$sql = 'SELECT c.*, ci.nome AS cidade_nome FROM cj_candidatos c '
			.'LEFT JOIN cidades ci ON ci.id = c.cidade_id '
			.'WHERE '.implode(' AND ', $where).' '
			.'ORDER BY c.updated_at DESC, c.id DESC LIMIT '.$lim;
		$stmt = (new Database())->execute($sql);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}
}
