<?php

namespace App\Common\Helpers;

use App\Model\Entity\AgendaPlano;
use App\Model\Entity\AgendaAvulso;
use App\Model\Entity\Horarios;
use App\Model\Entity\LmsSessaoCota;
use App\Model\Entity\LmsCurso;
use App\Model\Db\Database;
use PDO;

/**
 * Janela de acesso LMS por agenda (plano semanal + avulso/reposição) e cota diária (padrão 2 aulas).
 */
class LmsAgendaAcessoHelper {

	public const COTA_PADRAO = 2;
	public const TOLERANCIA_MIN = 15;
	public const TZ = 'America/Sao_Paulo';

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$a = $db->execute("SHOW TABLES LIKE 'agenda_avulso'");
			$b = $db->execute("SHOW TABLES LIKE 'lms_sessao_cota'");
			$ok = $a && $a->rowCount() > 0 && $b && $b->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function agora(): \DateTimeImmutable {
		try {
			return new \DateTimeImmutable('now', new \DateTimeZone(self::TZ));
		} catch (\Throwable $e) {
			return new \DateTimeImmutable('now');
		}
	}

	/**
	 * Trilha comercial da escola do aluno para gate de agenda LMS.
	 * Não usa curso.id_trilha (no criador da vitrine os IDs são de outro tenant).
	 */
	public static function idTrilhaAgendaAluno(int $idAluno, int $idAdminEscola): int {
		if ($idAluno <= 0 || $idAdminEscola <= 0) {
			return 0;
		}
		$mat = AgendaHelper::getMatriculaAtivaAluno($idAluno, $idAdminEscola);
		if ($mat && (int)($mat['id_trilha'] ?? 0) > 0) {
			return (int)$mat['id_trilha'];
		}
		try {
			$row = AgendaPlano::getPlanos(
				'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdminEscola.' AND ativo = 1 AND id_trilha > 0',
				'id DESC',
				1,
				'id_trilha'
			)->fetch(PDO::FETCH_ASSOC);
			if ($row && (int)($row['id_trilha'] ?? 0) > 0) {
				return (int)$row['id_trilha'];
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
		if (self::tabelasExistem()) {
			try {
				$row = AgendaAvulso::getAll(
					'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdminEscola.' AND ativo = 1 AND id_trilha > 0',
					'data DESC, id DESC',
					1,
					'id_trilha'
				)->fetch(PDO::FETCH_ASSOC);
				if ($row && (int)($row['id_trilha'] ?? 0) > 0) {
					return (int)$row['id_trilha'];
				}
			} catch (\Throwable $e) {
				/* ignore */
			}
		}
		return 0;
	}

	/** Alinha com AgendaHelper::diaSemanaData (1=seg … 6=sáb; domingo → 1). */
	public static function diaSemanaHoje(\DateTimeImmutable $now = null): int {
		$now = $now ?: self::agora();
		$w = (int)$now->format('w');
		return $w === 0 ? 1 : $w;
	}

	/**
	 * Janela ativa agora para aluno+trilha (plano recorrente ou avulso).
	 * @return array{fonte:string,inicio:string,fim:string,aulas_cota:int,label:string}|null
	 */
	public static function janelaAtiva(int $idAluno, int $idAdmin, int $idTrilha, \DateTimeImmutable $now = null): ?array {
		$now = $now ?: self::agora();
		$data = $now->format('Y-m-d');
		$diaSemana = self::diaSemanaHoje($now);
		$agoraMin = self::horaParaMinutos($now->format('H:i:s'));

		$candidatos = [];

		// Planos semanais do dia
		$planos = AgendaPlano::getPlanos(
			'agenda_plano.id_admin = '.(int)$idAdmin.'
			AND agenda_plano.id_aluno = '.(int)$idAluno.'
			AND agenda_plano.id_trilha = '.(int)$idTrilha.'
			AND agenda_plano.ativo = 1
			AND agenda_plano.dia_semana = '.(int)$diaSemana.'
			AND agenda_plano.data_inicio <= "'.$data.'"
			AND (agenda_plano.data_fim IS NULL OR agenda_plano.data_fim >= "'.$data.'")',
			null, null,
			'agenda_plano.*, horarios.inicio AS h_inicio, horarios.final AS h_final',
			'INNER JOIN horarios ON horarios.id = agenda_plano.id_horario'
		);
		while ($row = $planos->fetch(PDO::FETCH_ASSOC)) {
			$candidatos[] = [
				'fonte' => 'plano',
				'inicio' => (string)$row['h_inicio'],
				'fim' => (string)$row['h_final'],
				'aulas_cota' => self::COTA_PADRAO,
			];
		}

		if (self::tabelasExistem()) {
			$avulsos = AgendaAvulso::getAll(
				'agenda_avulso.id_admin = '.(int)$idAdmin.'
				AND agenda_avulso.id_aluno = '.(int)$idAluno.'
				AND agenda_avulso.id_trilha = '.(int)$idTrilha.'
				AND agenda_avulso.ativo = 1
				AND agenda_avulso.data = "'.$data.'"',
				null, null,
				'agenda_avulso.*, horarios.inicio AS h_inicio, horarios.final AS h_final',
				'INNER JOIN horarios ON horarios.id = agenda_avulso.id_horario'
			);
			while ($row = $avulsos->fetch(PDO::FETCH_ASSOC)) {
				$candidatos[] = [
					'fonte' => 'avulso',
					'inicio' => (string)$row['h_inicio'],
					'fim' => (string)$row['h_final'],
					'aulas_cota' => max(1, min(10, (int)($row['aulas_cota'] ?? self::COTA_PADRAO))),
				];
			}
		}

		foreach ($candidatos as $c) {
			$ini = self::horaParaMinutos($c['inicio']);
			$fim = self::horaParaMinutos($c['fim']) + self::TOLERANCIA_MIN;
			if ($agoraMin >= $ini && $agoraMin <= $fim) {
				$c['label'] = substr($c['inicio'], 0, 5).'–'.substr($c['fim'], 0, 5)
					.($c['fonte'] === 'avulso' ? ' (reposição)' : '');
				return $c;
			}
		}
		return null;
	}

	/** Próxima janela futura (hoje depois ou próximos 7 dias) para mensagem ao aluno. */
	public static function proximaJanela(int $idAluno, int $idAdmin, int $idTrilha, \DateTimeImmutable $now = null): ?array {
		$now = $now ?: self::agora();
		for ($d = 0; $d < 8; $d++) {
			$dia = $now->modify('+'.$d.' day');
			$data = $dia->format('Y-m-d');
			$diaSemana = self::diaSemanaHoje($dia);
			$slots = [];

			$planos = AgendaPlano::getPlanos(
				'agenda_plano.id_admin = '.(int)$idAdmin.'
				AND agenda_plano.id_aluno = '.(int)$idAluno.'
				AND agenda_plano.id_trilha = '.(int)$idTrilha.'
				AND agenda_plano.ativo = 1
				AND agenda_plano.dia_semana = '.(int)$diaSemana.'
				AND agenda_plano.data_inicio <= "'.$data.'"
				AND (agenda_plano.data_fim IS NULL OR agenda_plano.data_fim >= "'.$data.'")',
				null, null,
				'horarios.inicio AS h_inicio, horarios.final AS h_final',
				'INNER JOIN horarios ON horarios.id = agenda_plano.id_horario'
			);
			while ($row = $planos->fetch(PDO::FETCH_ASSOC)) {
				$slots[] = ['data' => $data, 'inicio' => $row['h_inicio'], 'fim' => $row['h_final'], 'fonte' => 'plano'];
			}

			if (self::tabelasExistem()) {
				$avulsos = AgendaAvulso::getAll(
					'agenda_avulso.id_admin = '.(int)$idAdmin.'
					AND agenda_avulso.id_aluno = '.(int)$idAluno.'
					AND agenda_avulso.id_trilha = '.(int)$idTrilha.'
					AND agenda_avulso.ativo = 1
					AND agenda_avulso.data = "'.$data.'"',
					null, null,
					'horarios.inicio AS h_inicio, horarios.final AS h_final',
					'INNER JOIN horarios ON horarios.id = agenda_avulso.id_horario'
				);
				while ($row = $avulsos->fetch(PDO::FETCH_ASSOC)) {
					$slots[] = ['data' => $data, 'inicio' => $row['h_inicio'], 'fim' => $row['h_final'], 'fonte' => 'avulso'];
				}
			}

			usort($slots, static function ($a, $b) {
				return strcmp((string)$a['inicio'], (string)$b['inicio']);
			});

			foreach ($slots as $s) {
				$start = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data.' '.self::normalizaHora($s['inicio']), $now->getTimezone());
				if (!$start) {
					$start = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $data.' '.substr($s['inicio'], 0, 5), $now->getTimezone());
				}
				if ($start && $start > $now) {
					return [
						'data' => $data,
						'inicio' => substr((string)$s['inicio'], 0, 5),
						'fim' => substr((string)$s['fim'], 0, 5),
						'fonte' => $s['fonte'],
						'label' => $data.' '.substr((string)$s['inicio'], 0, 5).'–'.substr((string)$s['fim'], 0, 5),
					];
				}
			}
		}
		return null;
	}

	/** @return int[] IDs de aulas já consumidas na cota de hoje */
	public static function aulasConsumidasHoje(int $idAluno, int $idAdmin, \DateTimeImmutable $now = null): array {
		if (!self::tabelasExistem()) {
			return [];
		}
		$now = $now ?: self::agora();
		$row = LmsSessaoCota::getDia($idAdmin, $idAluno, $now->format('Y-m-d'));
		return $row instanceof LmsSessaoCota ? $row->idsAulas() : [];
	}

	public static function registrarAulaNaCota(int $idAluno, int $idAdmin, int $idAula, \DateTimeImmutable $now = null): void {
		if (!self::tabelasExistem() || $idAula <= 0) {
			return;
		}
		$now = $now ?: self::agora();
		$data = $now->format('Y-m-d');
		$row = LmsSessaoCota::getDia($idAdmin, $idAluno, $data);
		if (!$row instanceof LmsSessaoCota) {
			$row = new LmsSessaoCota();
			$row->id_admin = $idAdmin;
			$row->id_aluno = $idAluno;
			$row->data = $data;
			$row->aulas_ids = '[]';
		}
		$ids = $row->idsAulas();
		if (!in_array($idAula, $ids, true)) {
			$ids[] = $idAula;
			$row->salvarIds($ids);
		}
	}

	/**
	 * Decide se aula incompleta pode ser avançada agora.
	 * Aulas concluídas / precisa_revisar sempre liberadas para revisão.
	 *
	 * @param int[] $idsIncompletasOrdenadas IDs de aulas LMS ainda não concluídas (ordem do currículo)
	 * @return array{allowed:bool,reason:?string,message:?string,janela:?array,liberadas:int[]}
	 */
	public static function avaliarAcessoAula(
		int $idAluno,
		int $idAdmin,
		int $idTrilha,
		int $idAula,
		bool $concluidaOuRevisao,
		array $idsIncompletasOrdenadas
	): array {
		if ($concluidaOuRevisao) {
			return [
				'allowed' => true,
				'reason' => null,
				'message' => null,
				'janela' => $idTrilha > 0 ? self::janelaAtiva($idAluno, $idAdmin, $idTrilha) : null,
				'liberadas' => [],
			];
		}

		// Curso EAD independente (sem trilha comercial): sem restrição de agenda
		if ($idTrilha <= 0) {
			return [
				'allowed' => true,
				'reason' => null,
				'message' => null,
				'janela' => ['label' => 'EAD livre', 'aulas_cota' => 999],
				'liberadas' => $idsIncompletasOrdenadas,
			];
		}

		$janela = self::janelaAtiva($idAluno, $idAdmin, $idTrilha);
		$consumidas = self::aulasConsumidasHoje($idAluno, $idAdmin);
		$liberadas = [];

		if (!$janela) {
			$prox = self::proximaJanela($idAluno, $idAdmin, $idTrilha);
			$msg = $prox
				? 'Novas aulas liberam no horário agendado: '.$prox['label'].'. Você pode revisar o que já concluiu.'
				: 'Sem horário agendado. Peça à escola um horário ou reposição para liberar novas aulas.';
			return [
				'allowed' => false,
				'reason' => 'fora_horario',
				'message' => $msg,
				'janela' => null,
				'liberadas' => [],
				'proxima' => $prox,
			];
		}

		$cotaMax = (int)$janela['aulas_cota'];
		$slots = max(0, $cotaMax - count($consumidas));
		foreach ($idsIncompletasOrdenadas as $id) {
			$id = (int)$id;
			if (in_array($id, $consumidas, true)) {
				$liberadas[] = $id;
				continue;
			}
			if ($slots > 0) {
				$liberadas[] = $id;
				$slots--;
			}
		}

		if (in_array($idAula, $liberadas, true) || in_array($idAula, $consumidas, true)) {
			return [
				'allowed' => true,
				'reason' => null,
				'message' => null,
				'janela' => $janela,
				'liberadas' => $liberadas,
			];
		}

		return [
			'allowed' => false,
			'reason' => 'cota_esgotada',
			'message' => 'Cota de '. $cotaMax .' aula(s) desta sessão esgotada. Revise o que já fez ou aguarde o próximo horário.',
			'janela' => $janela,
			'liberadas' => $liberadas,
		];
	}

	/** Payload para GET /me/access-window e banner do portal. */
	public static function accessWindow(int $idAluno, int $idAdmin, int $idTrilha): array {
		$janela = self::janelaAtiva($idAluno, $idAdmin, $idTrilha);
		$consumidas = self::aulasConsumidasHoje($idAluno, $idAdmin);
		$cota = $janela ? (int)$janela['aulas_cota'] : self::COTA_PADRAO;
		$prox = $janela ? null : self::proximaJanela($idAluno, $idAdmin, $idTrilha);
		return [
			'active' => $janela !== null,
			'window' => $janela,
			'quotaMax' => $cota,
			'quotaUsed' => count($consumidas),
			'quotaRemaining' => $janela ? max(0, $cota - count($consumidas)) : 0,
			'consumedLessonIds' => array_map('strval', $consumidas),
			'nextWindow' => $prox,
			'message' => $janela
				? 'Sessão ativa ('.$janela['label'].'). Restam '.max(0, $cota - count($consumidas)).' aula(s) novas nesta sessão.'
				: ($prox
					? 'Fora do horário. Próxima liberação: '.$prox['label'].'.'
					: 'Sem horário agendado para este curso.'),
		];
	}

	public static function idsIncompletasDoCurso(
		LmsCurso $curso,
		int $idAluno,
		int $idAdminConteudo,
		?int $idAdminEscola = null
	): array {
		$idEscola = ($idAdminEscola !== null && $idAdminEscola > 0) ? $idAdminEscola : $idAdminConteudo;
		$ids = [];
		$progressMap = [];
		foreach (\App\Model\Entity\LmsProgressoAula::listByAluno($idAluno, $idEscola) as $p) {
			$progressMap[(int)$p->id_aula] = $p;
		}
		foreach (\App\Model\Entity\LmsModulo::listByCurso((int)$curso->id, $idAdminConteudo) as $mod) {
			foreach (\App\Model\Entity\LmsAula::listByModulo((int)$mod->id, $idAdminConteudo) as $aula) {
				$prog = $progressMap[(int)$aula->id] ?? null;
				$unidadeOk = $prog && (int)($prog->unidade_aprovada ?? 0) === 1;
				$precisaRevisar = $prog && (int)($prog->precisa_revisar ?? 0) === 1;
				$assistida = $prog && !empty($prog->concluida_em) && !$precisaRevisar;
				$semAval = count(LmsUnidadeAvaliacaoHelper::itensAvaliados((int)$aula->id, $idAdminConteudo)) === 0;
				$completed = $unidadeOk || ($semAval && $assistida);
				if (!$completed) {
					$ids[] = (int)$aula->id;
				}
			}
		}
		return $ids;
	}

	private static function horaParaMinutos(string $hora): int {
		$hora = self::normalizaHora($hora);
		$p = array_map('intval', explode(':', $hora));
		return ($p[0] ?? 0) * 60 + ($p[1] ?? 0);
	}

	private static function normalizaHora(string $hora): string {
		$hora = trim($hora);
		if (preg_match('/^\d{1,2}:\d{2}/', $hora, $m)) {
			$parts = explode(':', $hora);
			return sprintf('%02d:%02d:%02d', (int)$parts[0], (int)($parts[1] ?? 0), (int)($parts[2] ?? 0));
		}
		return '00:00:00';
	}
}
