<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class ProspeccaoEmpresa {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'master_prospeccao_empresas'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return array{novos:int,atualizados:int} */
	public static function upsertFromGoogle(array $item, string $queryOrigem): array {
		if (!self::tabelaExiste()) {
			return ['novos' => 0, 'atualizados' => 0];
		}
		$placeId = trim((string)($item['placeId'] ?? ''));
		if ($placeId === '') {
			return ['novos' => 0, 'atualizados' => 0];
		}

		$stmt = (new Database())->execute(
			'SELECT id FROM master_prospeccao_empresas WHERE place_id = "'.addslashes($placeId).'" LIMIT 1'
		);
		$existe = $stmt && $stmt->fetch(PDO::FETCH_ASSOC);

		$nota = isset($item['nota']) && $item['nota'] !== null && $item['nota'] !== ''
			? (float)$item['nota']
			: null;

		$dados = [
			'place_id'         => $placeId,
			'nome'             => mb_substr(trim((string)($item['nome'] ?? '')), 0, 255),
			'endereco'         => self::nullOrSubstr($item['endereco'] ?? null, 500),
			'telefone'         => self::nullOrSubstr($item['telefone'] ?? null, 40),
			'whatsapp_digits'  => self::nullOrSubstr($item['whatsappDigits'] ?? null, 20),
			'maps_url'         => self::nullOrSubstr($item['mapsUrl'] ?? null, 500),
			'site_url'         => self::nullOrSubstr($item['site'] ?? null, 500),
			'nota'             => $nota,
			'query_origem'     => mb_substr(trim($queryOrigem), 0, 255),
		];

		if ($existe) {
			unset($dados['place_id']);
			(new Database('master_prospeccao_empresas'))->update(
				'place_id = "'.addslashes($placeId).'"',
				$dados
			);
			return ['novos' => 0, 'atualizados' => 1];
		}

		(new Database('master_prospeccao_empresas'))->insert($dados);
		return ['novos' => 1, 'atualizados' => 0];
	}

	public static function excluir(int $id): bool {
		if (!self::tabelaExiste() || $id <= 0) {
			return false;
		}
		return (new Database('master_prospeccao_empresas'))->delete('id = '.(int)$id);
	}

	private static function nullOrSubstr($value, int $max): ?string {
		if ($value === null || trim((string)$value) === '') {
			return null;
		}
		return mb_substr(trim((string)$value), 0, $max);
	}
}
