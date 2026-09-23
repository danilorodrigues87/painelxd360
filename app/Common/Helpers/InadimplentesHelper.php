<?php

namespace App\Common\Helpers;

use App\Model\Entity\FinanceiroAcordo;

/**
 * Relatório de inadimplentes e abandono (sem presença no diário).
 */
class InadimplentesHelper {

	private static function pdo(): \PDO {
		static $pdo = null;
		if ($pdo instanceof \PDO) {
			return $pdo;
		}
		$pdo = new \PDO(
			'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
			getenv('DB_USER'),
			getenv('DB_PASS'),
			[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
		);
		return $pdo;
	}

	/** @return array{status_matricula:string,dias_atraso_min:int,parcelas_atraso_min:int,id_trilha:int,busca:string,page:int,per_page:int} */
	public static function parseFiltrosInadimplentes(array $in): array {
		$status = MatriculaStatusHelper::normalizarFiltroStatusMatricula(
			(string)($in['status_matricula'] ?? 'ativa'),
			'ativa'
		);
		$dias = max(1, min(365, (int)($in['dias_atraso_min'] ?? 1)));
		$parc = max(1, min(6, (int)($in['parcelas_atraso_min'] ?? 1)));
		$idTrilha = max(0, (int)($in['id_trilha'] ?? 0));
		$busca = trim((string)($in['busca'] ?? ''));
		$page = max(1, (int)($in['page'] ?? 1));
		$perPage = (int)($in['per_page'] ?? 50);
		if ($perPage <= 0) {
			$perPage = 0;
		} else {
			$perPage = min(500, max(10, $perPage));
		}
		return [
			'status_matricula' => $status,
			'dias_atraso_min' => $dias,
			'parcelas_atraso_min' => $parc,
			'id_trilha' => $idTrilha,
			'busca' => $busca,
			'page' => $page,
			'per_page' => $perPage,
		];
	}

	/** @return array{meses_sem_presenca:int,status_matricula:string,somente_com_debito:bool,id_trilha:int,busca:string,page:int,per_page:int} */
	public static function parseFiltrosAbandono(array $in): array {
		$meses = max(1, min(12, (int)($in['meses_sem_presenca'] ?? 3)));
		$status = MatriculaStatusHelper::normalizarFiltroStatusMatricula(
			(string)($in['status_matricula'] ?? 'ativa'),
			'ativa'
		);
		return [
			'meses_sem_presenca' => $meses,
			'status_matricula' => $status,
			'somente_com_debito' => !empty($in['somente_com_debito']),
			'id_trilha' => max(0, (int)($in['id_trilha'] ?? 0)),
			'busca' => trim((string)($in['busca'] ?? '')),
			'page' => max(1, (int)($in['page'] ?? 1)),
			'per_page' => self::parseFiltrosInadimplentes($in)['per_page'],
		];
	}

	private static function sqlStatusMatricula(string $status, string $alias = 'm'): string {
		return MatriculaStatusHelper::sqlFiltroInadimplentes($status, $alias);
	}

	/**
	 * @return array{ok:bool,linhas:array,totais:array,total_registros:int,page:int,per_page:int,filtros:array}
	 */
	public static function listarInadimplentes(int $idAdmin, array $filtros): array {
		$f = self::parseFiltrosInadimplentes($filtros);
		$dataLimite = date('Y-m-d', strtotime('-'.$f['dias_atraso_min'].' days'));
		$abertoSql = FinanceiroAlunoHelper::sqlTituloAberto('c.status');

		$params = [
			'id_admin' => $idAdmin,
			'data_limite' => $dataLimite,
			'parcelas_min' => $f['parcelas_atraso_min'],
		];

		$whereExtra = self::sqlStatusMatricula($f['status_matricula'], 'm');
		if ($f['id_trilha'] > 0) {
			$whereExtra .= ' AND m.id_trilha = :id_trilha';
			$params['id_trilha'] = $f['id_trilha'];
		}
		if ($f['busca'] !== '') {
			$whereExtra .= ' AND (u.nome LIKE :busca OR u.email LIKE :busca OR u.cpf LIKE :busca)';
			$params['busca'] = '%'.$f['busca'].'%';
		}

		$sql = '
			SELECT
				m.id AS id_matricula,
				m.id_aluno,
				m.status AS status_matricula,
				u.nome,
				u.email,
				u.whatsapp,
				t.nome AS curso,
				COUNT(c.id) AS qtd_atraso,
				MIN(c.vencimento) AS primeiro_venc,
				MAX(c.vencimento) AS ultimo_venc
			FROM caixa c
			INNER JOIN matriculas m ON m.id = c.id_ref AND m.id_admin = c.id_admin
			INNER JOIN usuarios u ON u.id = m.id_aluno AND u.id_admin = c.id_admin
			INNER JOIN trilhas t ON t.id = m.id_trilha
			WHERE c.id_admin = :id_admin
			  AND c.tipo_transacao = "Entrada"
			  AND '.$abertoSql.'
			  AND (c.id_acordo IS NULL OR c.id_acordo = 0)
			  AND c.vencimento <= :data_limite
			  AND '.$whereExtra.'
			GROUP BY m.id, m.id_aluno, m.status, u.nome, u.email, u.whatsapp, t.nome
			HAVING COUNT(c.id) >= :parcelas_min
			ORDER BY qtd_atraso DESC, primeiro_venc ASC, u.nome ASC
		';

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute($params);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

		$acordosPorAluno = self::alunosComAcordoVencido($idAdmin, $dataLimite);
		$dividaCache = [];
		$linhas = [];
		$somaDivida = 0.0;

		foreach ($rows as $row) {
			$idAluno = (int)($row['id_aluno'] ?? 0);
			$statusMat = (int)($row['status_matricula'] ?? 0);
			$divida = self::dividaAlunoCached($idAdmin, $idAluno, $dividaCache);
			$linha = [
				'id_matricula' => (int)$row['id_matricula'],
				'id_aluno' => $idAluno,
				'nome' => (string)($row['nome'] ?? ''),
				'email' => (string)($row['email'] ?? ''),
				'whatsapp' => (string)($row['whatsapp'] ?? ''),
				'curso' => (string)($row['curso'] ?? ''),
				'status_matricula' => $statusMat,
				'status_label' => MatriculaStatusHelper::labelStatus($statusMat),
				'qtd_parcelas_atraso' => (int)($row['qtd_atraso'] ?? 0),
				'primeiro_vencimento' => (string)($row['primeiro_venc'] ?? ''),
				'primeiro_vencimento_br' => DateTimeHelper::databr((string)($row['primeiro_venc'] ?? '')),
				'ultimo_vencimento' => (string)($row['ultimo_venc'] ?? ''),
				'divida_total' => (float)($divida['total_com_encargos'] ?? 0),
				'qtd_vencidos_aluno' => (int)($divida['qtd_vencidos'] ?? 0),
				'tem_acordo_vencido' => !empty($acordosPorAluno[$idAluno]),
				'pode_cancelar' => $statusMat === MatriculaStatusHelper::STATUS_ANDAMENTO,
				'pode_regularizar' => $statusMat === MatriculaStatusHelper::STATUS_ENCERRADO
					&& MatriculaStatusHelper::contarTitulosAbertosMatricula((int)$row['id_matricula'], $idAdmin) > 0,
			];
			$somaDivida += $linha['divida_total'];
			$linhas[] = $linha;
		}

		$total = count($linhas);
		if ($f['per_page'] > 0) {
			$offset = ($f['page'] - 1) * $f['per_page'];
			$linhas = array_slice($linhas, $offset, $f['per_page']);
		}

		return [
			'ok' => true,
			'linhas' => $linhas,
			'total_registros' => $total,
			'page' => $f['page'],
			'per_page' => $f['per_page'],
			'filtros' => $f,
			'totais' => [
				'registros' => $total,
				'soma_divida' => round($somaDivida, 2),
			],
		];
	}

	/**
	 * @return array{ok:bool,linhas:array,totais:array,total_registros:int,page:int,per_page:int,filtros:array}
	 */
	public static function listarAbandono(int $idAdmin, array $filtros): array {
		$f = self::parseFiltrosAbandono($filtros);
		$meses = $f['meses_sem_presenca'];

		$params = ['id_admin' => $idAdmin, 'meses' => $meses];
		$whereExtra = self::sqlStatusMatricula($f['status_matricula'], 'm');
		if ($f['id_trilha'] > 0) {
			$whereExtra .= ' AND m.id_trilha = :id_trilha';
			$params['id_trilha'] = $f['id_trilha'];
		}
		if ($f['busca'] !== '') {
			$whereExtra .= ' AND (u.nome LIKE :busca OR u.email LIKE :busca OR u.cpf LIKE :busca)';
			$params['busca'] = '%'.$f['busca'].'%';
		}

		$sql = '
			SELECT
				m.id AS id_matricula,
				m.id_aluno,
				m.status AS status_matricula,
				u.nome,
				u.email,
				u.whatsapp,
				t.nome AS curso,
				(
					SELECT MAX(aa.data_aula)
					FROM agenda_aulas aa
					INNER JOIN presencas p ON p.agenda_aula_id = aa.id AND p.id_admin = aa.id_admin
					WHERE aa.id_admin = m.id_admin
					  AND aa.id_aluno = m.id_aluno
					  AND p.status IN ("presente", "reposicao")
				) AS ultima_presenca
			FROM matriculas m
			INNER JOIN usuarios u ON u.id = m.id_aluno AND u.id_admin = m.id_admin
			INNER JOIN trilhas t ON t.id = m.id_trilha
			WHERE m.id_admin = :id_admin
			  AND '.$whereExtra.'
			  AND NOT EXISTS (
				SELECT 1
				FROM agenda_aulas aa
				INNER JOIN presencas p ON p.agenda_aula_id = aa.id AND p.id_admin = aa.id_admin
				WHERE aa.id_admin = m.id_admin
				  AND aa.id_aluno = m.id_aluno
				  AND aa.data_aula >= DATE_SUB(CURDATE(), INTERVAL :meses MONTH)
				  AND p.status IN ("presente", "reposicao")
			  )
			ORDER BY ultima_presenca ASC, u.nome ASC
		';

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute($params);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

		$hoje = date('Y-m-d');
		$dataLimiteInad = date('Y-m-d', strtotime('-1 days'));
		$dividaCache = [];
		$inadimplenteCache = [];
		$linhas = [];
		$somaDivida = 0.0;

		foreach ($rows as $row) {
			$idAluno = (int)($row['id_aluno'] ?? 0);
			$statusMat = (int)($row['status_matricula'] ?? 0);
			$ultimaPres = (string)($row['ultima_presenca'] ?? '');
			$diasSem = null;
			if ($ultimaPres !== '' && $ultimaPres !== '0000-00-00') {
				$diff = DateTimeHelper::subtrairDatas($ultimaPres, $hoje);
				$diasSem = isset($diff->days) ? (int)$diff->days : (int)$diff->d;
			}

			$ehInad = self::alunoInadimplenteCached($idAdmin, $idAluno, $dataLimiteInad, $inadimplenteCache);
			$divida = self::dividaAlunoCached($idAdmin, $idAluno, $dividaCache);
			$dividaTotal = (float)($divida['total_com_encargos'] ?? 0);

			if ($f['somente_com_debito'] && $dividaTotal <= 0 && !$ehInad) {
				continue;
			}

			$linha = [
				'id_matricula' => (int)$row['id_matricula'],
				'id_aluno' => $idAluno,
				'nome' => (string)($row['nome'] ?? ''),
				'email' => (string)($row['email'] ?? ''),
				'whatsapp' => (string)($row['whatsapp'] ?? ''),
				'curso' => (string)($row['curso'] ?? ''),
				'status_matricula' => $statusMat,
				'status_label' => MatriculaStatusHelper::labelStatus($statusMat),
				'ultima_presenca' => $ultimaPres,
				'ultima_presenca_br' => $ultimaPres !== '' && $ultimaPres !== '0000-00-00'
					? DateTimeHelper::databr($ultimaPres) : '—',
				'dias_sem_presenca' => $diasSem,
				'meses_sem_presenca' => $meses,
				'inadimplente' => $ehInad,
				'divida_total' => $dividaTotal,
				'pode_cancelar' => $statusMat === MatriculaStatusHelper::STATUS_ANDAMENTO,
				'pode_regularizar' => $statusMat === MatriculaStatusHelper::STATUS_ENCERRADO
					&& MatriculaStatusHelper::contarTitulosAbertosMatricula((int)$row['id_matricula'], $idAdmin) > 0,
			];
			$somaDivida += $dividaTotal;
			$linhas[] = $linha;
		}

		$total = count($linhas);
		if ($f['per_page'] > 0) {
			$offset = ($f['page'] - 1) * $f['per_page'];
			$linhas = array_slice($linhas, $offset, $f['per_page']);
		}

		return [
			'ok' => true,
			'linhas' => $linhas,
			'total_registros' => $total,
			'page' => $f['page'],
			'per_page' => $f['per_page'],
			'filtros' => $f,
			'totais' => [
				'registros' => $total,
				'soma_divida' => round($somaDivida, 2),
			],
		];
	}

	/** @return array<int,true> */
	private static function alunosComAcordoVencido(int $idAdmin, string $dataLimite): array {
		$out = [];
		if (!FinanceiroAcordo::tabelasExistem() || !FinanceiroAcordo::caixaTemIdAcordo()) {
			return $out;
		}
		$abertoSql = FinanceiroAlunoHelper::sqlTituloAberto('c.status');
		$sql = '
			SELECT DISTINCT fa.id_aluno
			FROM caixa c
			INNER JOIN financeiro_acordos fa ON fa.id = c.id_acordo AND fa.id_admin = c.id_admin
			WHERE c.id_admin = :id_admin
			  AND c.tipo_transacao = "Entrada"
			  AND '.$abertoSql.'
			  AND c.id_acordo > 0
			  AND c.vencimento <= :data_limite
		';
		$stmt = self::pdo()->prepare($sql);
		$stmt->execute(['id_admin' => $idAdmin, 'data_limite' => $dataLimite]);
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
			$id = (int)($row['id_aluno'] ?? 0);
			if ($id > 0) {
				$out[$id] = true;
			}
		}
		return $out;
	}

	/** @param array<int,array> $cache */
	private static function dividaAlunoCached(int $idAdmin, int $idAluno, array &$cache): array {
		if ($idAluno <= 0) {
			return ['total_com_encargos' => 0, 'qtd_vencidos' => 0];
		}
		if (!isset($cache[$idAluno])) {
			$res = EncargosContratoHelper::calcularDividaAluno($idAdmin, $idAluno);
			$cache[$idAluno] = !empty($res['ok']) ? $res : ['total_com_encargos' => 0, 'qtd_vencidos' => 0];
		}
		return $cache[$idAluno];
	}

	/** @param array<int,bool> $cache */
	private static function alunoInadimplenteCached(int $idAdmin, int $idAluno, string $dataLimite, array &$cache): bool {
		if ($idAluno <= 0) {
			return false;
		}
		if (!isset($cache[$idAluno])) {
			$abertoSql = FinanceiroAlunoHelper::sqlTituloAberto('c.status');
			$sql = '
				SELECT COUNT(*) AS n FROM caixa c
				INNER JOIN matriculas m ON m.id = c.id_ref AND m.id_admin = c.id_admin
				WHERE c.id_admin = :id_admin
				  AND m.id_aluno = :id_aluno
				  AND c.tipo_transacao = "Entrada"
				  AND '.$abertoSql.'
				  AND c.vencimento <= :data_limite
				LIMIT 1
			';
			$stmt = self::pdo()->prepare($sql);
			$stmt->execute([
				'id_admin' => $idAdmin,
				'id_aluno' => $idAluno,
				'data_limite' => $dataLimite,
			]);
			$n = (int)($stmt->fetchColumn() ?: 0);
			$cache[$idAluno] = $n > 0;
		}
		return $cache[$idAluno];
	}

	/** @return string[][] */
	public static function colunasCsvInadimplentes(): array {
		return [
			['key' => 'nome', 'label' => 'Aluno'],
			['key' => 'email', 'label' => 'E-mail'],
			['key' => 'whatsapp', 'label' => 'WhatsApp'],
			['key' => 'id_matricula', 'label' => 'Matrícula'],
			['key' => 'curso', 'label' => 'Curso'],
			['key' => 'status_label', 'label' => 'Status contrato'],
			['key' => 'qtd_parcelas_atraso', 'label' => 'Parcelas atraso'],
			['key' => 'primeiro_vencimento_br', 'label' => '1º vencimento'],
			['key' => 'divida_total', 'label' => 'Dívida atualizada'],
			['key' => 'tem_acordo_vencido', 'label' => 'Acordo vencido'],
		];
	}

	/** @return string[][] */
	public static function colunasCsvAbandono(): array {
		return [
			['key' => 'nome', 'label' => 'Aluno'],
			['key' => 'email', 'label' => 'E-mail'],
			['key' => 'id_matricula', 'label' => 'Matrícula'],
			['key' => 'curso', 'label' => 'Curso'],
			['key' => 'status_label', 'label' => 'Status'],
			['key' => 'ultima_presenca_br', 'label' => 'Última presença'],
			['key' => 'dias_sem_presenca', 'label' => 'Dias sem presença'],
			['key' => 'inadimplente', 'label' => 'Inadimplente'],
			['key' => 'divida_total', 'label' => 'Dívida atualizada'],
		];
	}

	/** @param array<int,array<string,mixed>> $linhas */
	public static function linhasParaCsv(array $linhas, array $colunas): string {
		$sep = ';';
		$out = [];
		$out[] = implode($sep, array_map(static function ($c) {
			return self::csvEsc($c['label']);
		}, $colunas));
		foreach ($linhas as $row) {
			$cells = [];
			foreach ($colunas as $col) {
				$v = $row[$col['key']] ?? '';
				if (is_bool($v)) {
					$v = $v ? 'Sim' : 'Não';
				} elseif ($col['key'] === 'divida_total') {
					$v = number_format((float)$v, 2, ',', '.');
				}
				$cells[] = self::csvEsc((string)$v);
			}
			$out[] = implode($sep, $cells);
		}
		return "\xEF\xBB\xBF".implode("\r\n", $out);
	}

	private static function csvEsc(string $s): string {
		if (strpos($s, ';') !== false || strpos($s, '"') !== false || strpos($s, "\n") !== false) {
			return '"'.str_replace('"', '""', $s).'"';
		}
		return $s;
	}
}
