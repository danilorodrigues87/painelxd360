<?php

namespace App\Common\Helpers;

use App\Common\Communication\EvolutionApiService;
use App\Model\Entity\CrmLeads;

class CampanhaSegmentoHelper {

	public static function getTipos(): array {
		return [
			'alunos_matriculados'    => 'Alunos matriculados (ativos)',
			'alunos_menores_18'      => 'Alunos menores de 18 anos',
			'alunos_maiores_18'      => 'Alunos com 18 anos ou mais',
			'ex_alunos'              => 'Ex-alunos (sem matrícula ativa)',
			'emails_invalidos_alunos'=> 'Alunos com e-mail inválido (ativos e inativos)',
			'aniversariantes_mes'    => 'Aniversariantes do mês',
			'aniversariantes_dia'    => 'Aniversariantes de hoje',
			'leads'                  => 'Leads do CRM',
			'inadimplentes'          => 'Inadimplentes (mensalidades em atraso)',
			'whatsapp_grupos'        => 'Grupos e listas de transmissão (WhatsApp)',
		];
	}

	/**
	 * @param string $canal email|whatsapp
	 */
	public static function resolverDestinatarios(int $idAdmin, array $segmento, string $canal = 'email'): array {
		$canal = $canal === 'whatsapp' ? 'whatsapp' : 'email';
		$tipo = $segmento['tipo'] ?? 'alunos_matriculados';

		// Destinos manuais (JID de grupo/lista) — só WhatsApp
		if ($tipo === 'whatsapp_grupos') {
			return self::destinosGruposListas($segmento);
		}

		switch ($tipo) {
			case 'ex_alunos':
				$lista = self::exAlunos($idAdmin, $canal);
				break;
			case 'aniversariantes_mes':
				$lista = self::aniversariantesComFiltro($idAdmin, $segmento, 'mes', $canal);
				break;
			case 'aniversariantes_dia':
				$lista = self::aniversariantesComFiltro($idAdmin, $segmento, 'hoje', $canal);
				break;
			case 'aniversariantes_dia_matriculados':
				$lista = self::aniversariantesComFiltro(
					$idAdmin,
					array_merge($segmento, ['aniv_situacao' => 'ativos']),
					'hoje',
					$canal
				);
				break;
			case 'leads':
				$lista = self::leads($idAdmin, $segmento, $canal);
				break;
			case 'inadimplentes':
				$lista = self::inadimplentes($idAdmin, $segmento, $canal);
				break;
			case 'alunos_menores_18':
				$lista = self::alunosPorIdade($idAdmin, $canal, true);
				break;
			case 'alunos_maiores_18':
				$lista = self::alunosPorIdade($idAdmin, $canal, false);
				break;
			case 'emails_invalidos_alunos':
				$lista = self::emailsInvalidosAlunos($idAdmin, $canal);
				if ($canal === 'whatsapp') {
					return self::filtrarComWhatsapp($lista);
				}
				return [];
			case 'alunos_matriculados':
			default:
				$lista = self::alunosMatriculados($idAdmin, $canal);
				break;
		}

		return $canal === 'whatsapp'
			? self::filtrarComWhatsapp($lista)
			: self::filtrarComEmail($lista);
	}

	/** @param array{destinos?:array} $segmento */
	private static function destinosGruposListas(array $segmento): array {
		$lista = [];
		$vistos = [];
		foreach ($segmento['destinos'] ?? [] as $d) {
			if (!is_array($d)) {
				continue;
			}
			$jid = EvolutionApiService::normalizarDestino((string)($d['jid'] ?? ''));
			if ($jid === '' || !EvolutionApiService::isJidGrupoOuLista($jid)) {
				continue;
			}
			if (isset($vistos[$jid])) {
				continue;
			}
			$vistos[$jid] = true;
			$kind = (($d['kind'] ?? '') === 'lista' || strpos(strtolower($jid), '@broadcast') !== false)
				? 'lista'
				: 'grupo';
			$lista[] = [
				'destinatario_tipo' => $kind,
				'destinatario_id'   => null,
				'nome'              => trim((string)($d['nome'] ?? '')) ?: $jid,
				'contato'           => $jid,
				'curso'             => '',
			];
		}
		return $lista;
	}

	public static function aplicarVariaveis(string $texto, array $vars): string {
		$mapa = [
			'{nome}'     => $vars['nome'] ?? '',
			'{email}'    => $vars['email'] ?? $vars['contato'] ?? '',
			'{whatsapp}' => $vars['whatsapp'] ?? $vars['contato'] ?? '',
			'{telefone}' => $vars['whatsapp'] ?? $vars['contato'] ?? '',
			'{curso}'    => $vars['curso'] ?? '',
			'{escola}'   => $vars['escola'] ?? '',
			'{horario}'  => $vars['horario'] ?? '',
			'{data}'     => self::formatarDataVariavel($vars['data'] ?? ''),
			'{valor_debito}' => $vars['valor_debito'] ?? '',
			'{qtd_parcelas_atraso}' => $vars['qtd_parcelas_atraso'] ?? '',
			'{primeiro_vencimento_atraso}' => $vars['primeiro_vencimento_atraso'] ?? '',
		];

		return str_replace(array_keys($mapa), array_values($mapa), $texto);
	}

	/** Exibe data em dd/mm/aaaa para o usuário (aceita Y-m-d ou já formatada). */
	private static function formatarDataVariavel(string $data): string {
		$data = trim($data);
		if ($data === '') {
			return '';
		}
		if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $data)) {
			return DiarioWhatsappHelper::dataBr($data);
		}
		return $data;
	}

	/** Converte HTML de campanha/e-mail em texto para WhatsApp. */
	public static function textoParaWhatsapp(string $html): string {
		$t = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$t = preg_replace('#<(br|/?p|/?div|/?li|/?tr)[^>]*>#i', "\n", $t) ?? $t;
		$t = strip_tags($t);
		$t = preg_replace("/[ \t]+/", ' ', $t) ?? $t;
		$t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
		return trim($t);
	}

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

	private static function filtrarComEmail(array $destinatarios): array {
		$unicos = [];
		$vistos = [];

		foreach ($destinatarios as $item) {
			$email = EmailValidator::normalizar($item['contato'] ?? '');
			if (!EmailValidator::isValido($email)) {
				continue;
			}
			if (isset($vistos[$email])) {
				continue;
			}
			$vistos[$email] = true;
			$item['contato'] = $email;
			$unicos[] = $item;
		}

		return $unicos;
	}

	private static function filtrarComWhatsapp(array $destinatarios): array {
		$unicos = [];
		$vistos = [];

		foreach ($destinatarios as $item) {
			$tel = EvolutionApiService::normalizarTelefone((string)($item['contato'] ?? ''));
			if ($tel === '' || strlen($tel) < 12) {
				continue;
			}
			if (isset($vistos[$tel])) {
				continue;
			}
			$vistos[$tel] = true;
			$item['contato'] = $tel;
			$unicos[] = $item;
		}

		return $unicos;
	}

	private static function alunosMatriculados(int $idAdmin, string $canal): array {
		$hoje = date('Y-m-d');
		$campo = $canal === 'whatsapp' ? 'u.whatsapp' : 'u.email';
		$sql = '
			SELECT DISTINCT u.id, u.nome, '.$campo.' AS contato, t.nome AS curso
			FROM usuarios u
			INNER JOIN matriculas m ON m.id_aluno = u.id AND m.id_admin = u.id_admin
			LEFT JOIN trilhas t ON t.id = m.id_trilha
			WHERE u.id_admin = :id_admin
			  AND u.nivel = "Cliente"
			  AND m.status = 0
			  AND (m.fim IS NULL OR m.fim >= :hoje)
			  AND '.$campo.' IS NOT NULL
			  AND '.$campo.' != ""
		';

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute(['id_admin' => $idAdmin, 'hoje' => $hoje]);

		return self::mapearLinhas($stmt->fetchAll(\PDO::FETCH_ASSOC), 'aluno');
	}

	/** Todos os alunos (Clientes) com data de nascimento, filtrados por idade. */
	private static function alunosPorIdade(int $idAdmin, string $canal, bool $menorDe18): array {
		$campo = $canal === 'whatsapp' ? 'u.whatsapp' : 'u.email';
		$condIdade = $menorDe18
			? 'u.nascimento > DATE_SUB(CURDATE(), INTERVAL 18 YEAR)'
			: 'u.nascimento <= DATE_SUB(CURDATE(), INTERVAL 18 YEAR)';
		$sql = '
			SELECT DISTINCT u.id, u.nome, '.$campo.' AS contato, "" AS curso
			FROM usuarios u
			WHERE u.id_admin = :id_admin
			  AND u.nivel = "Cliente"
			  AND u.nascimento IS NOT NULL
			  AND u.nascimento != "0000-00-00"
			  AND '.$condIdade.'
			  AND '.$campo.' IS NOT NULL
			  AND '.$campo.' != ""
		';

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute(['id_admin' => $idAdmin]);

		return self::mapearLinhas($stmt->fetchAll(\PDO::FETCH_ASSOC), 'aluno');
	}

	/** Alunos (matriculados ou não) com e-mail preenchido e inválido — WhatsApp usa o número cadastrado. */
	private static function emailsInvalidosAlunos(int $idAdmin, string $canal): array {
		$sql = '
			SELECT u.id, u.nome, u.email, u.whatsapp
			FROM usuarios u
			WHERE u.id_admin = :id_admin
			  AND u.nivel = "Cliente"
			  AND u.email IS NOT NULL
			  AND u.email != ""
		';

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute(['id_admin' => $idAdmin]);

		$lista = [];
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$email = EmailValidator::normalizar($row['email'] ?? '');
			if ($email === '' || EmailValidator::isValido($email)) {
				continue;
			}
			$contato = $canal === 'whatsapp'
				? trim((string)($row['whatsapp'] ?? ''))
				: $email;
			$lista[] = [
				'destinatario_tipo' => 'aluno',
				'destinatario_id'   => (int)($row['id'] ?? 0),
				'nome'              => $row['nome'] ?? '',
				'contato'           => $contato,
				'email_cadastro'    => $email,
				'curso'             => '',
			];
		}

		return $lista;
	}

	private static function exAlunos(int $idAdmin, string $canal): array {
		$hoje = date('Y-m-d');
		$campo = $canal === 'whatsapp' ? 'u.whatsapp' : 'u.email';
		$sql = '
			SELECT DISTINCT u.id, u.nome, '.$campo.' AS contato, "" AS curso
			FROM usuarios u
			WHERE u.id_admin = :id_admin
			  AND u.nivel = "Cliente"
			  AND '.$campo.' IS NOT NULL
			  AND '.$campo.' != ""
			  AND u.id NOT IN (
			    SELECT m.id_aluno
			    FROM matriculas m
			    WHERE m.id_admin = :id_admin2
			      AND m.status = 0
			      AND (m.fim IS NULL OR m.fim >= :hoje)
			  )
		';

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute(['id_admin' => $idAdmin, 'id_admin2' => $idAdmin, 'hoje' => $hoje]);

		return self::mapearLinhas($stmt->fetchAll(\PDO::FETCH_ASSOC), 'aluno');
	}

	/**
	 * Reutiliza filtros de AniversariantesHelper (situação do aluno, inadimplência, etc.).
	 * @param string $periodo mes|hoje
	 */
	private static function aniversariantesComFiltro(int $idAdmin, array $segmento, string $periodo, string $canal): array {
		$situacao = AniversariantesHelper::normalizarSituacao((string)($segmento['aniv_situacao'] ?? 'todos'));
		$raw = AniversariantesHelper::listar($idAdmin, $periodo, '', null, $situacao, null);
		$lista = [];

		foreach ($raw as $row) {
			$contato = $canal === 'whatsapp'
				? trim((string)($row['whatsapp'] ?? ''))
				: trim((string)($row['email'] ?? ''));
			if ($contato === '') {
				continue;
			}
			$lista[] = [
				'destinatario_tipo' => 'aluno',
				'destinatario_id'   => (int)($row['id'] ?? 0),
				'nome'              => (string)($row['nome'] ?? ''),
				'contato'           => $contato,
				'curso'             => (string)($row['curso'] ?? ''),
			];
		}

		return $lista;
	}

	/** Normaliza filtro de situação para segmentos de aniversariantes. */
	public static function normalizarSegmentoAniversariantes(array $in): array {
		return [
			'aniv_situacao' => AniversariantesHelper::normalizarSituacao((string)($in['aniv_situacao'] ?? 'todos')),
		];
	}

	private static function leads(int $idAdmin, array $segmento, string $canal): array {
		$where = 'id_admin = '.(int)$idAdmin;
		$status = $segmento['status_lead'] ?? '';

		if ($status !== '' && in_array($status, ['novo','em_atendimento','matriculado','perdido'], true)) {
			$where .= ' AND status = "'.addslashes($status).'"';
		}

		if ($canal === 'whatsapp') {
			$where .= ' AND whatsapp IS NOT NULL AND whatsapp != ""';
		} else {
			$where .= ' AND email IS NOT NULL AND email != ""';
		}

		$results = CrmLeads::getLeads($where, 'nome ASC');
		$lista = [];

		while ($lead = $results->fetchObject(CrmLeads::class)) {
			$lista[] = [
				'destinatario_tipo' => 'lead',
				'destinatario_id'   => (int)$lead->id,
				'nome'              => $lead->nome,
				'contato'           => trim($canal === 'whatsapp' ? (string)$lead->whatsapp : (string)$lead->email),
				'curso'             => $lead->curso_interesse ?? '',
			];
		}

		return $lista;
	}

	private static function inadimplentes(int $idAdmin, array $segmento, string $canal): array {
		$f = self::parseFiltrosInadimplentesCampanha($segmento);
		$diasMin = max(0, (int)($segmento['dias_atraso_min'] ?? 0));
		if ($diasMin <= 0) {
			$diasMin = 1;
		}
		$dataLimite = date('Y-m-d', strtotime('-'.$diasMin.' days'));
		$campo = $canal === 'whatsapp' ? 'u.whatsapp' : 'u.email';
		$abertoSql = FinanceiroAlunoHelper::sqlTituloAberto('c.status');
		$whereStatusMat = self::sqlStatusMatriculaCampanha($f['status_matricula'], 'm');

		$sqlMat = '
			SELECT
				u.id,
				u.nome,
				'.$campo.' AS contato,
				COUNT(*) AS qtd_atraso,
				MIN(c.vencimento) AS primeiro_venc,
				SUBSTRING_INDEX(GROUP_CONCAT(c.descricao ORDER BY c.vencimento ASC SEPARATOR " · "), " · ", 1) AS curso
			FROM caixa c
			INNER JOIN matriculas m ON m.id = c.id_ref AND m.id_admin = c.id_admin
			INNER JOIN usuarios u ON u.id = m.id_aluno AND u.id_admin = c.id_admin
			WHERE c.id_admin = :id_admin
			  AND c.tipo_transacao = "Entrada"
			  AND '.$abertoSql.'
			  AND (c.id_acordo IS NULL OR c.id_acordo = 0)
			  AND c.vencimento <= :data_limite
			  AND '.$whereStatusMat.'
			  AND '.$campo.' IS NOT NULL
			  AND '.$campo.' != ""
			GROUP BY u.id, u.nome, '.$campo.'
		';

		$stmt = self::pdo()->prepare($sqlMat);
		$stmt->execute([
			'id_admin' => $idAdmin,
			'data_limite' => $dataLimite,
		]);
		$porAluno = [];
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
			$id = (int)($row['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$porAluno[$id] = $row;
		}

		if (in_array($f['status_matricula'], ['todas', 'inativa'], true)
			&& \App\Model\Entity\FinanceiroAcordo::tabelasExistem()
			&& \App\Model\Entity\FinanceiroAcordo::caixaTemIdAcordo()) {
			$sqlAc = '
				SELECT
					u.id,
					u.nome,
					'.$campo.' AS contato,
					COUNT(*) AS qtd_atraso,
					MIN(c.vencimento) AS primeiro_venc,
					SUBSTRING_INDEX(GROUP_CONCAT(c.descricao ORDER BY c.vencimento ASC SEPARATOR " · "), " · ", 1) AS curso
				FROM caixa c
				INNER JOIN financeiro_acordos fa ON fa.id = c.id_acordo AND fa.id_admin = c.id_admin
				INNER JOIN usuarios u ON u.id = fa.id_aluno AND u.id_admin = c.id_admin
				WHERE c.id_admin = :id_admin
				  AND c.tipo_transacao = "Entrada"
				  AND '.$abertoSql.'
				  AND c.id_acordo > 0
				  AND c.vencimento <= :data_limite
				  AND fa.status = "ativo"
				  AND '.$campo.' IS NOT NULL
				  AND '.$campo.' != ""
				GROUP BY u.id, u.nome, '.$campo.'
			';
			$stmtAc = self::pdo()->prepare($sqlAc);
			$stmtAc->execute([
				'id_admin' => $idAdmin,
				'data_limite' => $dataLimite,
			]);
			foreach ($stmtAc->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
				$id = (int)($row['id'] ?? 0);
				if ($id <= 0) {
					continue;
				}
				if (!isset($porAluno[$id])) {
					$porAluno[$id] = $row;
					continue;
				}
				$porAluno[$id]['qtd_atraso'] = (int)$porAluno[$id]['qtd_atraso'] + (int)($row['qtd_atraso'] ?? 0);
				$pv = (string)($row['primeiro_venc'] ?? '');
				$atual = (string)($porAluno[$id]['primeiro_venc'] ?? '');
				if ($pv !== '' && ($atual === '' || $pv < $atual)) {
					$porAluno[$id]['primeiro_venc'] = $pv;
				}
			}
		}

		$lista = [];
		foreach ($porAluno as $row) {
			$qtd = (int)($row['qtd_atraso'] ?? 0);
			if ($f['modo'] === 'exato') {
				if ($qtd !== $f['qtd']) {
					continue;
				}
			} elseif ($qtd < $f['qtd']) {
				continue;
			}
			$idAluno = (int)($row['id'] ?? 0);
			$desde = !empty($row['primeiro_venc'])
				? date('d/m/Y', strtotime($row['primeiro_venc']))
				: '';
			$cursoBase = trim((string)($row['curso'] ?? ''));
			$valorDebito = '';
			$div = EncargosContratoHelper::calcularDividaAluno($idAdmin, $idAluno);
			if (!empty($div['ok'])) {
				$valorDebito = NumeroHelper::moedaBr(
					EncargosContratoHelper::valorDebitoCampanhaInadimplentes($idAdmin, $idAluno, $div)
				);
			}
			$lista[] = [
				'destinatario_tipo' => 'aluno',
				'destinatario_id'   => $idAluno,
				'nome'              => $row['nome'],
				'contato'           => trim((string)($row['contato'] ?? '')),
				'curso'             => trim(
					($cursoBase !== '' ? $cursoBase.' — ' : '')
					.$qtd.' parcela'.($qtd === 1 ? '' : 's').' em atraso'
					.($desde !== '' ? ' (desde '.$desde.')' : '')
					.($valorDebito !== '' ? ' · débito R$ '.$valorDebito : '')
				),
				'valor_debito' => $valorDebito,
				'qtd_parcelas_atraso' => (string)$qtd,
				'primeiro_vencimento_atraso' => $desde,
			];
		}

		usort($lista, static function ($a, $b) {
			$qa = (int)($a['qtd_parcelas_atraso'] ?? 0);
			$qb = (int)($b['qtd_parcelas_atraso'] ?? 0);
			if ($qa !== $qb) {
				return $qb <=> $qa;
			}
			return strcmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? ''));
		});

		return $lista;
	}

	/**
	 * Normaliza filtros do segmento inadimplentes (formulário ou JSON legado).
	 * @return array{modo:string,qtd:int,status_matricula:string,parcelas_atraso_modo:string,parcelas_atraso_qtd:int,parcelas_atraso_min?:int}
	 */
	public static function normalizarSegmentoInadimplentes(array $in): array {
		$modo = trim((string)($in['parcelas_atraso_modo'] ?? 'min'));
		if (!in_array($modo, ['min', 'exato'], true)) {
			$modo = 'min';
		}
		$qtd = (int)($in['parcelas_atraso_qtd'] ?? $in['parcelas_atraso_min'] ?? 1);
		if ($qtd < 1) {
			$qtd = 1;
		}
		if ($qtd > 12) {
			$qtd = 12;
		}
		$status = MatriculaStatusHelper::normalizarFiltroStatusMatricula(
			(string)($in['status_matricula'] ?? 'todas'),
			'todas'
		);
		$out = [
			'modo' => $modo,
			'qtd' => $qtd,
			'status_matricula' => $status,
			'parcelas_atraso_modo' => $modo,
			'parcelas_atraso_qtd' => $qtd,
		];
		if ($modo === 'min') {
			$out['parcelas_atraso_min'] = $qtd;
		}
		return $out;
	}

	/** @return array{modo:string,qtd:int,status_matricula:string} */
	private static function parseFiltrosInadimplentesCampanha(array $segmento): array {
		$n = self::normalizarSegmentoInadimplentes($segmento);
		return [
			'modo' => $n['modo'],
			'qtd' => $n['qtd'],
			'status_matricula' => $n['status_matricula'],
		];
	}

	private static function sqlStatusMatriculaCampanha(string $status, string $alias = 'm'): string {
		return MatriculaStatusHelper::sqlFiltroInadimplentes($status, $alias);
	}

	private static function mapearLinhas(array $linhas, string $tipo): array {
		$lista = [];

		foreach ($linhas as $row) {
			$lista[] = [
				'destinatario_tipo' => $tipo,
				'destinatario_id'   => (int)($row['id'] ?? 0),
				'nome'              => $row['nome'] ?? '',
				'contato'           => trim((string)($row['contato'] ?? $row['email'] ?? $row['whatsapp'] ?? '')),
				'curso'             => $row['curso'] ?? '',
			];
		}

		return $lista;
	}
}
