<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjEmpresa {

	public $id;
	public $id_usuario;
	public $cnpj;
	public $razao_social;
	public $nome_fantasia;
	public $logo;
	public $whatsapp;
	public $email;
	public $contato_nome;
	public $cidade_id;
	public $bairro;
	public $uf;
	public $status = 'pendente';

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_empresas'");
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
		$row = (new Database('cj_empresas'))->select('id = '.(int)$id, null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public static function getByIdEnriched(int $id): ?array {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$sql = 'SELECT e.*, ci.nome AS cidade_nome FROM cj_empresas e '
			.'LEFT JOIN cidades ci ON ci.id = e.cidade_id '
			.'WHERE e.id = '.(int)$id.' LIMIT 1';
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
		$row = (new Database('cj_empresas'))->select('id_usuario = '.(int)$idUsuario, null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getByCnpj(string $cnpj): ?self {
		$cnpj = preg_replace('/\D+/', '', $cnpj) ?: '';
		if (!self::tabelaExiste() || strlen($cnpj) !== 14) {
			return null;
		}
		$row = (new Database('cj_empresas'))->select('cnpj = "'.$cnpj.'"', null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function inserir(array $dados): ?int {
		if (!self::tabelaExiste()) {
			return null;
		}
		$id = (new Database('cj_empresas'))->insert($dados);
		return $id ? (int)$id : null;
	}

	public static function atualizar(int $id, array $dados): bool {
		if (!self::tabelaExiste() || $id <= 0) {
			return false;
		}
		$dados = \App\Common\Helpers\ConectSchemaHelper::filtrar('cj_empresas', $dados);
		if ($dados === []) {
			return true;
		}
		return (new Database('cj_empresas'))->update('id = '.(int)$id, $dados);
	}

	public static function listarPublicas(?int $cidadeId = null, int $limit = 100, ?string $q = null): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$where = 'e.status = "aprovada"';
		if ($cidadeId !== null && $cidadeId > 0) {
			$where .= ' AND e.cidade_id = '.(int)$cidadeId;
		}
		if ($q !== null && trim($q) !== '') {
			$term = addslashes(trim($q));
			$where .= ' AND (e.nome_fantasia LIKE "%'.$term.'%" OR e.razao_social LIKE "%'.$term.'%")';
		}
		$sql = 'SELECT e.*, c.nome AS cidade_nome FROM cj_empresas e '
			.'LEFT JOIN cidades c ON c.id = e.cidade_id '
			.'WHERE '.$where.' ORDER BY e.nome_fantasia ASC LIMIT '.(int)$limit;
		$stmt = (new Database())->execute($sql);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}
}
