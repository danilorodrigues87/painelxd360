<?php

namespace App\Model\Entity;

use App\Common\Helpers\ConectEnderecoHelper;
use App\Model\Db\Database;
use PDO;

class CjAnuncio {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_anuncios'");
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
		$rows = self::queryLista(['id' => $id, 'status_any' => true, 'limit' => 1]);
		return $rows[0] ?? null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarPorEmpresa(int $idEmpresa, int $limit = 50): array {
		if (!self::tabelaExiste() || $idEmpresa <= 0) {
			return [];
		}
		return self::queryLista([
			'id_empresa' => $idEmpresa,
			'status_any' => true,
			'limit' => $limit,
		]);
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarMaster(array $filtros = []): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		return self::queryLista(array_merge(['status_any' => true, 'limit' => 200], $filtros));
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarAtivosPublico(string $slot, ?string $uf, ?int $cidadeId, int $limit = 8): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		return self::queryLista([
			'publico' => true,
			'slot' => $slot,
			'uf' => $uf,
			'cidade_id' => $cidadeId,
			'limit' => $limit,
		]);
	}

	/**
	 * @param array<string,mixed> $filtros
	 * @return array<int,array<string,mixed>>
	 */
	private static function queryLista(array $filtros): array {
		$where = ['a.publisher = "conecta_jovem"'];
		if (empty($filtros['status_any'])) {
			$where[] = 'a.status = "ativo"';
		}
		if (!empty($filtros['id'])) {
			$where[] = 'a.id = '.(int)$filtros['id'];
		}
		if (!empty($filtros['id_empresa'])) {
			$where[] = 'a.id_empresa = '.(int)$filtros['id_empresa'];
		}
		if (!empty($filtros['status'])) {
			$where[] = 'a.status = "'.addslashes((string)$filtros['status']).'"';
		}
		if (!empty($filtros['slot'])) {
			$where[] = 'a.slot = "'.addslashes((string)$filtros['slot']).'"';
		}
		if (!empty($filtros['publico'])) {
			$where[] = 'a.status = "ativo"';
			$where[] = '(a.inicio_em IS NULL OR a.inicio_em <= NOW())';
			$where[] = '(a.fim_em IS NULL OR a.fim_em >= NOW())';
			$uf = strtoupper(trim((string)($filtros['uf'] ?? '')));
			$cidadeId = (int)($filtros['cidade_id'] ?? 0);
			if ($uf !== '' || $cidadeId > 0) {
				$geo = ['(a.uf IS NULL AND a.cidade_id IS NULL)'];
				if ($uf !== '') {
					$geo[] = '(a.uf = "'.addslashes($uf).'" AND a.cidade_id IS NULL)';
				}
				if ($cidadeId > 0) {
					$geo[] = 'a.cidade_id = '.$cidadeId;
					$loc = ConectEnderecoHelper::localPorCidadeId($cidadeId);
					if (($loc['uf'] ?? '') !== '') {
						$geo[] = '(a.uf = "'.addslashes($loc['uf']).'" AND a.cidade_id IS NULL)';
					}
				}
				$where[] = '('.implode(' OR ', $geo).')';
			}
			unset($filtros['slot']);
		}
		$limit = max(1, min(200, (int)($filtros['limit'] ?? 50)));
		$sql = 'SELECT a.*, ci.nome AS cidade_nome, e.nome_fantasia AS empresa_nome '
			.'FROM cj_anuncios a '
			.'LEFT JOIN cidades ci ON ci.id = a.cidade_id '
			.'LEFT JOIN cj_empresas e ON e.id = a.id_empresa '
			.'WHERE '.implode(' AND ', $where).' '
			.'ORDER BY a.ordem ASC, a.id DESC LIMIT '.$limit;
		$stmt = (new Database())->execute($sql);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	public static function inserir(array $dados): int {
		return (int)(new Database('cj_anuncios'))->insert($dados);
	}

	public static function atualizar(int $id, array $dados): bool {
		return (new Database('cj_anuncios'))->update('id = '.(int)$id, $dados);
	}

	public static function excluir(int $id): bool {
		return (new Database('cj_anuncios'))->delete('id = '.(int)$id);
	}

	public static function contarPorEmpresa(int $idEmpresa): int {
		if (!self::tabelaExiste() || $idEmpresa <= 0) {
			return 0;
		}
		$row = (new Database('cj_anuncios'))->select(
			'id_empresa = '.(int)$idEmpresa.' AND status IN ("pendente","ativo","pausado")',
			null,
			null,
			'COUNT(*) AS q'
		)->fetch(PDO::FETCH_ASSOC);
		return (int)($row['q'] ?? 0);
	}

	/** Vagas do plano: apenas anúncios ativos ou aguardando aprovação (pausado libera vaga). */
	public static function contarOcupandoPlano(int $idEmpresa): int {
		if (!self::tabelaExiste() || $idEmpresa <= 0) {
			return 0;
		}
		$row = (new Database('cj_anuncios'))->select(
			'id_empresa = '.(int)$idEmpresa.' AND status IN ("pendente","ativo")',
			null,
			null,
			'COUNT(*) AS q'
		)->fetch(PDO::FETCH_ASSOC);
		return (int)($row['q'] ?? 0);
	}
}
