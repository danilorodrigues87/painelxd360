<?php

namespace App\Common\Helpers;

use App\Model\Db\Database;
use App\Model\Entity\CjAnalytics;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\CjCandidatoFormacao;
use App\Model\Entity\CjEmpresa;
use App\Model\Entity\CjVaga;
use PDO;

class ConectRelatoriosHelper {

	private const TIPO_LABEL = [
		'aluno'           => 'Aluno LMS',
		'externo'         => 'Cadastro portal',
		'escola_cadastro' => 'Cadastro escola',
	];

	/** @return array{de:string,ate:string,de_br:string,ate_br:string} */
	public static function normalizarPeriodo(string $de, string $ate): array {
		if ($de === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) {
			$de = date('Y-m-01');
		}
		if ($ate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
			$ate = date('Y-m-d');
		}
		if ($de > $ate) {
			[$de, $ate] = [$ate, $de];
		}
		return [
			'de'    => $de,
			'ate'   => $ate,
			'de_br' => date('d/m/Y', strtotime($de)),
			'ate_br'=> date('d/m/Y', strtotime($ate)),
		];
	}

	/** @return array<string,mixed> */
	public static function kpisResumo(string $de, string $ate): array {
		$periodo = self::normalizarPeriodo($de, $ate);
		$de = $periodo['de'];
		$ate = $periodo['ate'];
		$whereData = 'DATE(created_at) >= "'.addslashes($de).'" AND DATE(created_at) <= "'.addslashes($ate).'"';

		$candidatosNovos = 0;
		$empresasNovas = 0;
		$totalCandidatos = 0;
		$totalEmpresas = 0;
		$viewsVagas = 0;

		if (CjCandidato::tabelaExiste()) {
			$candidatosNovos = self::scalar(
				'SELECT COUNT(*) FROM cj_candidatos WHERE '.$whereData
			);
			$totalCandidatos = self::scalar('SELECT COUNT(*) FROM cj_candidatos');
		}
		if (CjEmpresa::tabelaExiste()) {
			$empresasNovas = self::scalar(
				'SELECT COUNT(*) FROM cj_empresas WHERE '.$whereData
			);
			$totalEmpresas = self::scalar(
				'SELECT COUNT(*) FROM cj_empresas WHERE status = "aprovada"'
			);
		}
		if (CjVaga::tabelaExiste()) {
			$viewsVagas = self::scalar(
				'SELECT COALESCE(SUM(views_count), 0) FROM cj_vagas WHERE status IN ("publicada","encerrada","pendente")'
			);
		}

		$visitas = 0;
		$visitantesUnicos = 0;
		$novosVisitantes = 0;
		$compartilhamentos = 0;
		$visitasCadastro = 0;
		$analyticsOk = CjAnalytics::tabelasExistem();
		if ($analyticsOk) {
			$visitas = CjAnalytics::contarVisitas($de, $ate);
			$visitantesUnicos = CjAnalytics::contarVisitantesUnicos($de, $ate);
			$novosVisitantes = CjAnalytics::contarNovosVisitantes($de, $ate);
			$compartilhamentos = CjAnalytics::contarCompartilhamentos($de, $ate);
			$visitasCadastro = CjAnalytics::visitasPaginaCadastro($de, $ate);
		}

		return [
			'periodo'              => $periodo,
			'candidatos_novos'     => $candidatosNovos,
			'empresas_novas'       => $empresasNovas,
			'total_candidatos'     => $totalCandidatos,
			'total_empresas'       => $totalEmpresas,
			'views_vagas_total'    => $viewsVagas,
			'visitas'              => $visitas,
			'visitantes_unicos'    => $visitantesUnicos,
			'novos_visitantes'     => $novosVisitantes,
			'compartilhamentos'    => $compartilhamentos,
			'visitas_cadastro'     => $visitasCadastro,
			'analytics_ok'         => $analyticsOk,
			'serie_diaria'         => $analyticsOk ? CjAnalytics::serieDiaria($de, $ate) : [],
			'top_paginas'          => $analyticsOk ? CjAnalytics::topPaginas($de, $ate) : [],
			'shares_plataforma'    => $analyticsOk ? CjAnalytics::sharesPorPlataforma($de, $ate) : [],
			'candidatos_por_escola'=> self::candidatosPorEscola($de, $ate),
		];
	}

	/** @return array<int,array{escola:string,qtd:int}> */
	public static function candidatosPorEscola(string $de, string $ate): array {
		if (!CjCandidato::tabelaExiste()) {
			return [];
		}
		$periodo = self::normalizarPeriodo($de, $ate);
		$de = $periodo['de'];
		$ate = $periodo['ate'];
		$stmt = (new Database())->execute(
			'SELECT COALESCE(e.nome, CONCAT("Escola #", c.id_admin)) AS escola, COUNT(*) AS qtd '
			.'FROM cj_candidatos c '
			.'LEFT JOIN clientes_assinantes e ON e.id = c.id_admin '
			.'WHERE DATE(c.created_at) >= "'.addslashes($de).'" '
			.'AND DATE(c.created_at) <= "'.addslashes($ate).'" '
			.'GROUP BY c.id_admin, e.nome '
			.'ORDER BY qtd DESC LIMIT 15'
		);
		$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'escola' => (string)($r['escola'] ?? '—'),
				'qtd'    => (int)($r['qtd'] ?? 0),
			];
		}
		return $out;
	}

	/** @return array<int,array{id:int,nome:string}> */
	public static function listarEscolasFiltro(): array {
		try {
			$stmt = (new Database())->execute(
				'SELECT id, nome FROM clientes_assinantes ORDER BY nome ASC LIMIT 500'
			);
			$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
		} catch (\Throwable $e) {
			return [];
		}
		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'id'   => (int)($r['id'] ?? 0),
				'nome' => (string)($r['nome'] ?? ''),
			];
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $filtros
	 * @return array{where:string,params:array<string,mixed>}
	 */
	private static function buildWhereCandidatos(array $filtros): array {
		$where = ['1=1'];
		$de = trim((string)($filtros['de'] ?? ''));
		$ate = trim((string)($filtros['ate'] ?? ''));
		if ($de !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) {
			$where[] = 'DATE(c.created_at) >= "'.addslashes($de).'"';
		}
		if ($ate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
			$where[] = 'DATE(c.created_at) <= "'.addslashes($ate).'"';
		}
		if (!empty($filtros['id_admin'])) {
			$where[] = 'c.id_admin = '.(int)$filtros['id_admin'];
		}
		if (!empty($filtros['uf'])) {
			$where[] = 'c.uf = "'.addslashes(strtoupper(substr((string)$filtros['uf'], 0, 2))).'"';
		}
		if (!empty($filtros['tipo']) && in_array($filtros['tipo'], ['aluno', 'externo', 'escola_cadastro'], true)) {
			$where[] = 'c.tipo = "'.addslashes((string)$filtros['tipo']).'"';
		}
		if (!empty($filtros['status']) && in_array($filtros['status'], ['ativo', 'inativo'], true)) {
			$where[] = 'c.status = "'.addslashes((string)$filtros['status']).'"';
		}
		if (!empty($filtros['q'])) {
			$q = addslashes(trim((string)$filtros['q']));
			$where[] = '(c.nome LIKE "%'.$q.'%" OR c.email LIKE "%'.$q.'%" OR c.whatsapp LIKE "%'.$q.'%")';
		}
		return ['where' => implode(' AND ', $where), 'params' => $filtros];
	}

	private static function sqlCandidatosBase(): string {
		$selo = '0';
		if (CjCandidatoFormacao::tabelaExiste()) {
			try {
				$stmt = (new Database())->execute("SHOW COLUMNS FROM cj_candidato_formacao LIKE 'selo_certificado'");
				if ($stmt && $stmt->rowCount() > 0) {
					$selo = 'EXISTS(SELECT 1 FROM cj_candidato_formacao f WHERE f.id_candidato = c.id AND f.selo_certificado = 1)';
				}
			} catch (\Throwable $e) {
				$selo = '0';
			}
		}
		return 'SELECT c.id, c.nome, c.email, c.whatsapp, c.tipo, c.status, c.uf, c.created_at, '
			.'ci.nome AS cidade_nome, '
			.'e.nome AS escola_nome, '
			.'('.$selo.') AS tem_selo '
			.'FROM cj_candidatos c '
			.'LEFT JOIN clientes_assinantes e ON e.id = c.id_admin '
			.'LEFT JOIN cidades ci ON ci.id = c.cidade_id ';
	}

	/** @param array<string,mixed> $filtros */
	public static function contarCandidatos(array $filtros): int {
		if (!CjCandidato::tabelaExiste()) {
			return 0;
		}
		$w = self::buildWhereCandidatos($filtros);
		return self::scalar(
			'SELECT COUNT(*) FROM cj_candidatos c WHERE '.$w['where']
		);
	}

	/**
	 * @param array<string,mixed> $filtros
	 * @return array<int,array<string,mixed>>
	 */
	public static function listarCandidatos(array $filtros, int $limit = 50, int $offset = 0): array {
		if (!CjCandidato::tabelaExiste()) {
			return [];
		}
		$w = self::buildWhereCandidatos($filtros);
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$sql = self::sqlCandidatosBase()
			.'WHERE '.$w['where'].' '
			.'ORDER BY c.created_at DESC, c.id DESC '
			.'LIMIT '.$limit.' OFFSET '.$offset;
		try {
			$stmt = (new Database())->execute($sql);
			$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
		} catch (\Throwable $e) {
			throw $e;
		}
		return array_map([self::class, 'mapCandidatoRow'], $rows);
	}

	/** @param array<string,mixed> $row */
	private static function mapCandidatoRow(array $row): array {
		$uf = (string)($row['uf'] ?? $row['estado_uf'] ?? '');
		$whatsapp = trim((string)($row['whatsapp'] ?? ''));
		return [
			'id'            => (int)($row['id'] ?? 0),
			'nome'          => (string)($row['nome'] ?? ''),
			'email'         => (string)($row['email'] ?? ''),
			'whatsapp'      => $whatsapp,
			'whatsappDigits'=> preg_replace('/\D+/', '', $whatsapp) ?: '',
			'tipo'          => (string)($row['tipo'] ?? ''),
			'tipoLabel'     => self::TIPO_LABEL[(string)($row['tipo'] ?? '')] ?? (string)($row['tipo'] ?? ''),
			'status'        => (string)($row['status'] ?? ''),
			'escolaNome'    => (string)($row['escola_nome'] ?? '—'),
			'cidadeNome'    => (string)($row['cidade_nome'] ?? '—'),
			'uf'            => $uf !== '' ? $uf : '—',
			'estadoNome'    => (string)($row['estado_nome'] ?? '—'),
			'temSelo'       => !empty($row['tem_selo']),
			'createdAt'     => (string)($row['created_at'] ?? ''),
			'createdAtBr'   => self::formatDataBr($row['created_at'] ?? ''),
		];
	}

	/** @param array<string,mixed> $filtros */
	public static function csvCandidatos(array $filtros): string {
		$rows = self::listarCandidatos($filtros, 5000, 0);
		$out = fopen('php://temp', 'r+');
		if ($out === false) {
			return '';
		}
		fwrite($out, "\xEF\xBB\xBF");
		fputcsv($out, [
			'ID', 'Nome', 'E-mail', 'WhatsApp', 'Tipo', 'Status', 'Escola', 'Cidade', 'UF', 'Estado', 'Selo', 'Cadastro',
		], ';');
		foreach ($rows as $r) {
			fputcsv($out, [
				$r['id'],
				$r['nome'],
				$r['email'],
				$r['whatsapp'],
				$r['tipoLabel'],
				$r['status'],
				$r['escolaNome'],
				$r['cidadeNome'],
				$r['uf'],
				$r['estadoNome'],
				!empty($r['temSelo']) ? 'Sim' : 'Não',
				$r['createdAtBr'],
			], ';');
		}
		rewind($out);
		$csv = stream_get_contents($out);
		fclose($out);
		return is_string($csv) ? $csv : '';
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

	private static function formatDataBr($value): string {
		if (!$value) {
			return '';
		}
		$ts = strtotime((string)$value);
		return $ts ? date('d/m/Y H:i', $ts) : (string)$value;
	}
}
