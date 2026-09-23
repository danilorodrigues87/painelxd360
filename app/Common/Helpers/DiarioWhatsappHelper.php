<?php

namespace App\Common\Helpers;

use App\Common\Communication\EvolutionApiService;
use App\Common\Communication\WhatsappEscolaService;
use App\Model\Entity\CampanhaFila;
use App\Model\Entity\Campanhas;
use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\ClientesAssinantes;

class DiarioWhatsappHelper {

	public const STATUS_VALIDOS = ['aguardando', 'presente', 'falta', 'justificada', 'reposicao'];

	/** @return list<string> */
	public static function statusPresencaValidos(): array {
		return self::STATUS_VALIDOS;
	}

	public static function normalizarStatus(?string $status): string {
		$status = trim((string)$status);
		return in_array($status, self::STATUS_VALIDOS, true) ? $status : 'aguardando';
	}

	public static function labelStatus(?string $status): string {
		$status = self::normalizarStatus($status);
		$labels = [
			'aguardando'  => 'Aguardando',
			'presente'    => 'Presente',
			'falta'       => 'Falta',
			'justificada' => 'Justificada',
			'reposicao'   => 'Reposição',
		];
		return $labels[$status] ?? $status;
	}

	/** Formata Y-m-d para dd/mm/aaaa. */
	public static function dataBr(?string $ymd): string {
		$ymd = trim((string)$ymd);
		if ($ymd === '') {
			return '';
		}
		$ts = strtotime($ymd);
		return $ts ? date('d/m/Y', $ts) : $ymd;
	}

	/** Horário legível (14:00 – 15:00). */
	public static function horarioBr(?string $inicio, ?string $final): string {
		$i = substr(trim((string)$inicio), 0, 5);
		$f = substr(trim((string)$final), 0, 5);
		if ($i === '' && $f === '') {
			return '';
		}
		if ($f === '') {
			return $i;
		}
		return $i.' – '.$f;
	}

	public static function mensagemLembretePadrao(): string {
		return "Olá, {nome}! 👋✨\n\n"
			."Sua aula de *{curso}* começa em breve 🕐 ({horario}).\n\n"
			."Contamos com sua presença! 📚💙\n\n"
			."— {escola}";
	}

	public static function mensagemFaltasPadrao(): string {
		return "Olá, {nome}! 👋\n\n"
			."Registramos sua ausência na aula de *{curso}* hoje ({data}) 📅\n\n"
			."Você pode agendar uma *aula de reposição*: são *3 reposições gratuitas*. "
			."Depois disso, consulte a secretaria sobre taxas de reposição. 🔄📞\n\n"
			."Estamos à disposição!\n"
			."— {escola}";
	}

	public static function getMensagens(int $idAdmin): array {
		$lembrete = self::mensagemLembretePadrao();
		$faltas = self::mensagemFaltasPadrao();
		if (EscolaIntegracoes::temColunasDiarioWhatsapp()) {
			$cfg = EscolaIntegracoes::getByIdAdmin($idAdmin);
			if ($cfg instanceof EscolaIntegracoes) {
				$t = trim((string)($cfg->diario_wa_lembrete_mensagem ?? ''));
				if ($t !== '') {
					$lembrete = $t;
				}
				$t = trim((string)($cfg->diario_wa_faltas_mensagem ?? ''));
				if ($t !== '') {
					$faltas = $t;
				}
			}
		}
		return [
			'lembrete' => $lembrete,
			'faltas'   => $faltas,
		];
	}

	public static function salvarMensagens(int $idAdmin, string $lembrete, string $faltas): bool {
		if (!EscolaIntegracoes::temColunasDiarioWhatsapp()) {
			return false;
		}
		$ob = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$ob instanceof EscolaIntegracoes) {
			$ob = new EscolaIntegracoes;
			$ob->id_admin = $idAdmin;
		}
		$ob->diario_wa_lembrete_mensagem = $lembrete;
		$ob->diario_wa_faltas_mensagem = $faltas;
		return $ob->salvarDiarioWhatsapp();
	}

	/** @return array{ok:bool,motivo?:string,conectado?:bool} */
	public static function podeUsar(int $idAdmin): array {
		$slugs = ModuleGateHelper::getSlugsEscola($idAdmin);
		if (!in_array('whatsapp', $slugs, true)) {
			return ['ok' => false, 'motivo' => 'Módulo WhatsApp não está no plano da escola.', 'conectado' => false];
		}
		if (!Campanhas::tabelaExiste()) {
			return ['ok' => false, 'motivo' => 'Tabelas de campanhas não configuradas.', 'conectado' => false];
		}
		$status = WhatsappEscolaService::status($idAdmin);
		$conectado = !empty($status['conectado']);
		if (!$conectado) {
			return ['ok' => false, 'motivo' => 'WhatsApp não está conectado. Pareie em Configurações → Comunicação.', 'conectado' => false];
		}
		return ['ok' => true, 'conectado' => true];
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

	private static function nomeEscola(int $idAdmin): string {
		$ob = ClientesAssinantes::getEscolaById($idAdmin);
		return $ob ? trim((string)($ob->nome ?? '')) : '';
	}

	/**
	 * Horários com aulas na data (para seleção do lembrete).
	 *
	 * @return list<array{id:int,label:string,inicio:string}>
	 */
	public static function listarHorariosDia(int $idAdmin, string $data, int $labId = 0): array {
		$sql = '
			SELECT DISTINCT h.id, h.inicio, h.final
			FROM agenda_aulas aa
			INNER JOIN horarios h ON h.id = aa.id_horario
			WHERE aa.id_admin = :id_admin AND aa.data_aula = :data
		';
		if ($labId > 0) {
			$sql .= ' AND aa.laboratorio_id = '.(int)$labId;
		}
		$sql .= ' ORDER BY h.inicio ASC';

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute(['id_admin' => $idAdmin, 'data' => $data]);

		$out = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$id = (int)($row['id'] ?? 0);
			if ($id <= 0) {
				continue;
			}
			$label = self::horarioBr($row['inicio'] ?? '', $row['final'] ?? '');
			$out[] = [
				'id'     => $id,
				'label'  => $label,
				'inicio' => substr((string)($row['inicio'] ?? ''), 0, 5),
			];
		}
		return $out;
	}

	/**
	 * Alunos agendados no horário selecionado (exclui quem já está presente/reposição).
	 *
	 * @return list<array{destinatario_id:int,nome:string,contato:string,curso:string,horario:string,data:string}>
	 */
	public static function resolverLembreteHorario(int $idAdmin, string $data, int $horarioId, int $labId = 0): array {
		if ($horarioId <= 0) {
			return [];
		}

		$sql = '
			SELECT DISTINCT aa.id AS agenda_aula_id, aa.id_aluno, u.nome, u.whatsapp AS contato,
				t.nome AS curso, h.inicio, h.final, aa.data_aula,
				COALESCE(p.status, "") AS presenca_status
			FROM agenda_aulas aa
			INNER JOIN usuarios u ON u.id = aa.id_aluno AND u.id_admin = aa.id_admin
			INNER JOIN trilhas t ON t.id = aa.id_trilha
			INNER JOIN horarios h ON h.id = aa.id_horario
			LEFT JOIN presencas p ON p.agenda_aula_id = aa.id
			WHERE aa.id_admin = :id_admin
			  AND aa.data_aula = :data
			  AND aa.id_horario = :horario_id
			  AND u.whatsapp IS NOT NULL AND u.whatsapp != ""
		';
		if ($labId > 0) {
			$sql .= ' AND aa.laboratorio_id = '.(int)$labId;
		}

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute([
			'id_admin'    => $idAdmin,
			'data'        => $data,
			'horario_id'  => $horarioId,
		]);

		$out = [];
		$vistos = [];
		$dataBr = self::dataBr($data);
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$st = trim((string)($row['presenca_status'] ?? ''));
			if (in_array($st, ['presente', 'reposicao'], true)) {
				continue;
			}
			$tel = EvolutionApiService::normalizarTelefone((string)($row['contato'] ?? ''));
			if ($tel === '' || strlen($tel) < 12) {
				continue;
			}
			$idAluno = (int)$row['id_aluno'];
			if (isset($vistos[$tel])) {
				continue;
			}
			$vistos[$tel] = true;
			$out[] = [
				'destinatario_id' => $idAluno,
				'nome'            => trim((string)$row['nome']),
				'contato'         => $tel,
				'curso'           => trim((string)$row['curso']),
				'horario'         => self::horarioBr($row['inicio'] ?? '', $row['final'] ?? ''),
				'data'            => $dataBr,
			];
		}
		return $out;
	}

	/**
	 * Alunos com falta registrada (presença salva) na data.
	 *
	 * @return list<array{destinatario_id:int,nome:string,contato:string,curso:string,horario:string,data:string}>
	 */
	public static function resolverFaltasDia(int $idAdmin, string $data, int $labId = 0): array {
		$sql = '
			SELECT DISTINCT aa.id_aluno, u.nome, u.whatsapp AS contato,
				t.nome AS curso, h.inicio, h.final, aa.data_aula
			FROM presencas p
			INNER JOIN agenda_aulas aa ON aa.id = p.agenda_aula_id
			INNER JOIN usuarios u ON u.id = aa.id_aluno AND u.id_admin = aa.id_admin
			INNER JOIN trilhas t ON t.id = aa.id_trilha
			INNER JOIN horarios h ON h.id = aa.id_horario
			WHERE p.id_admin = :id_admin
			  AND aa.data_aula = :data
			  AND p.status = "falta"
			  AND u.whatsapp IS NOT NULL AND u.whatsapp != ""
		';
		if ($labId > 0) {
			$sql .= ' AND aa.laboratorio_id = '.(int)$labId;
		}
		$sql .= ' ORDER BY u.nome ASC';

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute(['id_admin' => $idAdmin, 'data' => $data]);

		$out = [];
		$vistos = [];
		$dataBr = self::dataBr($data);
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$tel = EvolutionApiService::normalizarTelefone((string)($row['contato'] ?? ''));
			if ($tel === '' || strlen($tel) < 12) {
				continue;
			}
			$idAluno = (int)$row['id_aluno'];
			if (isset($vistos[$tel])) {
				continue;
			}
			$vistos[$tel] = true;
			$out[] = [
				'destinatario_id' => $idAluno,
				'nome'            => trim((string)$row['nome']),
				'contato'         => $tel,
				'curso'           => trim((string)$row['curso']),
				'horario'         => self::horarioBr($row['inicio'] ?? '', $row['final'] ?? ''),
				'data'            => $dataBr,
			];
		}
		return $out;
	}

	/**
	 * @param list<array{destinatario_id:int,nome:string,contato:string,curso:string,horario?:string,data?:string}> $destinatarios
	 * @return array{ok:bool,message?:string,campanha_id?:int,total?:int}
	 */
	public static function dispararCampanha(
		int $idAdmin,
		string $titulo,
		string $mensagemTpl,
		array $destinatarios,
		int $usuarioId,
		string $origem,
		string $data
	): array {
		$gate = self::podeUsar($idAdmin);
		if (empty($gate['ok'])) {
			return ['ok' => false, 'message' => $gate['motivo'] ?? 'WhatsApp indisponível.'];
		}
		if (empty($destinatarios)) {
			return ['ok' => false, 'message' => 'Nenhum destinatário com WhatsApp válido.'];
		}

		$varsPorId = [];
		foreach ($destinatarios as $d) {
			$did = (int)($d['destinatario_id'] ?? 0);
			if ($did > 0) {
				$varsPorId[(string)$did] = [
					'horario' => $d['horario'] ?? '',
					'data'    => $d['data'] ?? self::dataBr($data),
				];
			}
		}

		$segmento = json_encode([
			'tipo'                  => 'diario_manual',
			'origem'                => $origem,
			'data'                  => $data,
			'vars_por_destinatario' => $varsPorId,
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		$camp = new Campanhas;
		$camp->id_admin = $idAdmin;
		$camp->canal = 'whatsapp';
		$camp->tipo = 'diario';
		$camp->titulo = mb_substr($titulo, 0, 255);
		$camp->assunto = $camp->titulo;
		$camp->mensagem = $mensagemTpl;
		$camp->segmento = $segmento;
		$camp->status = 'rascunho';
		$camp->criada_por = $usuarioId;
		if (!$camp->cadastrar()) {
			return ['ok' => false, 'message' => 'Não foi possível criar a campanha.'];
		}

		$itens = [];
		foreach ($destinatarios as $dest) {
			$itens[] = [
				'campanha_id'       => (int)$camp->id,
				'id_admin'          => $idAdmin,
				'destinatario_tipo' => 'aluno',
				'destinatario_id'   => $dest['destinatario_id'] ?? null,
				'nome'              => $dest['nome'] ?? '',
				'contato'           => $dest['contato'],
				'curso'             => $dest['curso'] ?? '',
			];
		}
		CampanhaFila::inserirLote($itens);

		$camp->status = 'enviando';
		$camp->atualizar();
		$camp->recalcularTotais();

		$total = count($destinatarios);
		return [
			'ok'          => true,
			'message'     => $total.' mensagem(ns) na fila. O envio continua em segundo plano (intervalo entre disparos).',
			'campanha_id' => (int)$camp->id,
			'total'       => $total,
		];
	}
}
