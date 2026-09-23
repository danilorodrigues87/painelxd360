<?php

namespace App\Common\Helpers;

use App\Model\Db\Database;
use App\Model\Entity\ProspeccaoEmpresa;
use PDO;

class ProspeccaoEmpresasHelper {

	/**
	 * @param array<string,mixed> $filtros
	 * @return array{where:string}
	 */
	private static function buildWhere(array $filtros): array {
		$where = ['1=1'];
		if (!empty($filtros['com_whatsapp'])) {
			$where[] = 'whatsapp_digits IS NOT NULL AND whatsapp_digits <> ""';
		}
		if (!empty($filtros['q'])) {
			$q = addslashes(trim((string)$filtros['q']));
			$where[] = '(nome LIKE "%'.$q.'%" OR endereco LIKE "%'.$q.'%" OR telefone LIKE "%'.$q.'%" OR query_origem LIKE "%'.$q.'%")';
		}
		return ['where' => implode(' AND ', $where)];
	}

	/** @param array<string,mixed> $filtros */
	public static function contar(array $filtros): int {
		if (!ProspeccaoEmpresa::tabelaExiste()) {
			return 0;
		}
		$w = self::buildWhere($filtros);
		try {
			$stmt = (new Database())->execute(
				'SELECT COUNT(*) FROM master_prospeccao_empresas WHERE '.$w['where']
			);
			$row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : false;
			return (int)($row[0] ?? 0);
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/**
	 * @param array<string,mixed> $filtros
	 * @return array<int,array<string,mixed>>
	 */
	public static function listar(array $filtros, int $limit = 50, int $offset = 0): array {
		if (!ProspeccaoEmpresa::tabelaExiste()) {
			return [];
		}
		$w = self::buildWhere($filtros);
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$sql = 'SELECT * FROM master_prospeccao_empresas WHERE '.$w['where']
			.' ORDER BY importado_em DESC, id DESC LIMIT '.$limit.' OFFSET '.$offset;
		$stmt = (new Database())->execute($sql);
		$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
		return array_map([self::class, 'mapRow'], $rows);
	}

	/** @return array<string,mixed> */
	public static function stats(): array {
		if (!ProspeccaoEmpresa::tabelaExiste()) {
			return ['total' => 0, 'comWhatsapp' => 0, 'ultimaImportacao' => null, 'ultimaImportacaoBr' => '—'];
		}
		$total = self::scalar('SELECT COUNT(*) FROM master_prospeccao_empresas');
		$comWa = self::scalar(
			'SELECT COUNT(*) FROM master_prospeccao_empresas WHERE whatsapp_digits IS NOT NULL AND whatsapp_digits <> ""'
		);
		$ultima = self::scalarString(
			'SELECT MAX(importado_em) FROM master_prospeccao_empresas'
		);
		return [
			'total'              => $total,
			'comWhatsapp'        => $comWa,
			'ultimaImportacao'   => $ultima,
			'ultimaImportacaoBr' => self::formatDataBr($ultima),
		];
	}

	/**
	 * @return array{novos:int,atualizados:int,nextPageToken:?string,totalPagina:int,modo:string,requisicoes?:int,paginas?:int,categoria?:?string,categorias?:?int}
	 */
	public static function importarDoGoogle(
		string $query,
		?string $pageToken = null,
		string $estrategia = 'pagina',
		?int $categoriaIndex = null
	): array {
		$result = GooglePlacesHelper::searchAvancado($query, [
			'estrategia'      => $estrategia,
			'pageToken'       => $pageToken,
			'categoriaIndex'  => $categoriaIndex,
		]);
		if (empty($result['success'])) {
			throw new \RuntimeException((string)($result['message'] ?? 'Falha na busca Google.'));
		}

		$queryOrigem = self::montarQueryOrigem($query, $estrategia, $result);

		$novos = 0;
		$atualizados = 0;
		foreach (($result['items'] ?? []) as $item) {
			$r = ProspeccaoEmpresa::upsertFromGoogle($item, $queryOrigem);
			$novos += (int)($r['novos'] ?? 0);
			$atualizados += (int)($r['atualizados'] ?? 0);
		}

		return [
			'novos'          => $novos,
			'atualizados'    => $atualizados,
			'nextPageToken'  => $result['nextPageToken'] ?? null,
			'totalPagina'    => count($result['items'] ?? []),
			'modo'           => (string)($result['modo'] ?? 'geral'),
			'requisicoes'    => isset($result['requisicoes']) ? (int)$result['requisicoes'] : null,
			'paginas'        => isset($result['paginas']) ? (int)$result['paginas'] : null,
			'categoria'      => isset($result['categoria']) ? (string)$result['categoria'] : null,
			'categorias'     => isset($result['categorias']) ? (int)$result['categorias'] : null,
		];
	}

	/** @param array<string,mixed> $result */
	private static function montarQueryOrigem(string $query, string $estrategia, array $result): string {
		if (!empty($result['categoria'])) {
			return $query.' · '.$result['categoria'];
		}
		if (in_array($estrategia, ['categorias', 'categorias_todas_paginas'], true)) {
			return $query.' · categorias';
		}
		if ($estrategia === 'todas_paginas') {
			return $query.' · todas páginas';
		}
		return $query;
	}

	/** @param array<string,mixed> $filtros */
	public static function csv(array $filtros): string {
		$rows = self::listar($filtros, 5000, 0);
		$out = fopen('php://temp', 'r+');
		if ($out === false) {
			return '';
		}
		fwrite($out, "\xEF\xBB\xBF");
		fputcsv($out, [
			'ID', 'Place ID', 'Nome', 'Endereço', 'Telefone', 'WhatsApp URL',
			'Link Maps', 'Site', 'Nota', 'Query origem', 'Importado em', 'Atualizado em',
		], ';');
		foreach ($rows as $r) {
			fputcsv($out, [
				$r['id'],
				$r['placeId'],
				$r['nome'],
				$r['endereco'],
				$r['telefone'],
				$r['whatsappUrl'],
				$r['mapsUrl'],
				$r['siteUrl'],
				$r['nota'] !== null ? $r['nota'] : '',
				$r['queryOrigem'],
				$r['importadoEmBr'],
				$r['atualizadoEmBr'],
			], ';');
		}
		rewind($out);
		$csv = stream_get_contents($out);
		fclose($out);
		return is_string($csv) ? $csv : '';
	}

	/** @param array<string,mixed> $row */
	private static function mapRow(array $row): array {
		$digits = (string)($row['whatsapp_digits'] ?? '');
		return [
			'id'             => (int)($row['id'] ?? 0),
			'placeId'        => (string)($row['place_id'] ?? ''),
			'nome'           => (string)($row['nome'] ?? ''),
			'endereco'       => (string)($row['endereco'] ?? ''),
			'telefone'       => (string)($row['telefone'] ?? ''),
			'whatsappDigits' => $digits,
			'whatsappUrl'    => $digits !== '' ? 'https://wa.me/'.$digits : '',
			'mapsUrl'        => (string)($row['maps_url'] ?? ''),
			'siteUrl'        => (string)($row['site_url'] ?? ''),
			'nota'           => isset($row['nota']) && $row['nota'] !== null ? (float)$row['nota'] : null,
			'queryOrigem'    => (string)($row['query_origem'] ?? ''),
			'importadoEm'    => (string)($row['importado_em'] ?? ''),
			'importadoEmBr'  => self::formatDataBr($row['importado_em'] ?? ''),
			'atualizadoEm'   => (string)($row['atualizado_em'] ?? ''),
			'atualizadoEmBr' => self::formatDataBr($row['atualizado_em'] ?? ''),
		];
	}

	private static function scalar(string $sql): int {
		try {
			$stmt = (new Database())->execute($sql);
			$row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : false;
			return (int)($row[0] ?? 0);
		} catch (\Throwable $e) {
			return 0;
		}
	}

	private static function scalarString(string $sql): ?string {
		try {
			$stmt = (new Database())->execute($sql);
			$row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : false;
			$v = $row[0] ?? null;
			return $v !== null && (string)$v !== '' ? (string)$v : null;
		} catch (\Throwable $e) {
			return null;
		}
	}

	private static function formatDataBr($value): string {
		if (!$value) {
			return '—';
		}
		$ts = strtotime((string)$value);
		return $ts ? date('d/m/Y H:i', $ts) : (string)$value;
	}
}
