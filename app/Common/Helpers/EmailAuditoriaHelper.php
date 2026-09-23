<?php

namespace App\Common\Helpers;

use App\Common\Communication\EvolutionApiService;
use App\Model\Entity\CrmLeads;
use App\Model\Entity\User as EntityUser;
use App\Model\Entity\Responsaveis;

class EmailAuditoriaHelper {

	public static function auditarEscola(int $idAdmin, int $limite = 200): array {
		$invalidos = array_merge(
			self::auditarAlunos($idAdmin, $limite),
			self::auditarResponsaveis($idAdmin, $limite),
			self::auditarLeads($idAdmin, $limite)
		);

		return self::montarRelatorio($invalidos, $limite);
	}

	public static function auditarWhatsappEscola(int $idAdmin, int $limite = 200): array {
		$invalidos = array_merge(
			self::auditarAlunosWhatsapp($idAdmin, $limite),
			self::auditarResponsaveisWhatsapp($idAdmin, $limite),
			self::auditarLeadsWhatsapp($idAdmin, $limite)
		);

		return self::montarRelatorio($invalidos, $limite);
	}

	/** @return array{emails:array,whatsapp:array,total:int} */
	public static function auditarContatosEscola(int $idAdmin, int $limite = 200): array {
		$emails = self::auditarEscola($idAdmin, $limite);
		$whatsapp = self::auditarWhatsappEscola($idAdmin, $limite);

		return [
			'emails' => $emails,
			'whatsapp' => $whatsapp,
			'total' => (int)($emails['total'] ?? 0) + (int)($whatsapp['total'] ?? 0),
		];
	}

	public static function getRejeicaoWhatsapp(?string $whatsapp, bool $considerarAusente = false): ?string {
		$trim = trim((string)$whatsapp);
		if ($trim === '') {
			return $considerarAusente ? 'Ausente' : null;
		}

		$norm = EvolutionApiService::normalizarTelefone($trim);
		if ($norm === '') {
			return 'Formato inválido';
		}

		$len = strlen($norm);
		if ($len < 12 || $len > 13) {
			return 'Número incompleto ou inválido';
		}
		if (strpos($norm, '55') !== 0) {
			return 'DDI não reconhecido (use Brasil +55)';
		}

		$local = substr($norm, 2);
		if (preg_match('/^(\d)\1{8,}$/', $local)) {
			return 'Número fictício';
		}
		if (strlen($local) === 10 && $local[2] !== '9') {
			return 'Celular deve ter 9 dígitos (DDD + 9 + número)';
		}

		return null;
	}

	private static function montarRelatorio(array $invalidos, int $limite): array {
		usort($invalidos, function ($a, $b) {
			return strcmp($a['tipo'], $b['tipo']) ?: strcmp($a['nome'], $b['nome']);
		});

		$totalInvalidos = count($invalidos);
		$porMotivo = [];
		foreach ($invalidos as $item) {
			$motivo = $item['motivo'];
			$porMotivo[$motivo] = ($porMotivo[$motivo] ?? 0) + 1;
		}

		return [
			'total'      => $totalInvalidos,
			'por_motivo' => $porMotivo,
			'itens'      => array_slice($invalidos, 0, $limite),
			'truncado'   => $totalInvalidos > $limite,
		];
	}

	private static function auditarAlunos(int $idAdmin, int $limite): array {
		$lista = [];
		$results = EntityUser::getUser(
			'nivel = "Cliente" AND id_admin = '.(int)$idAdmin.' AND email IS NOT NULL AND email != ""',
			'nome ASC'
		);

		while ($row = $results->fetchObject(EntityUser::class)) {
			$motivo = EmailValidator::getRejeicao($row->email ?? '');
			if ($motivo === null) {
				continue;
			}
			$lista[] = [
				'tipo'    => 'Aluno',
				'id'      => (int)$row->id,
				'nome'    => $row->nome ?? '',
				'contato' => $row->email ?? '',
				'email'   => $row->email ?? '',
				'motivo'  => $motivo,
			];
			if (count($lista) >= $limite) {
				break;
			}
		}

		return $lista;
	}

	private static function auditarResponsaveis(int $idAdmin, int $limite): array {
		$lista = [];
		$results = Responsaveis::getRes(
			'id_admin = '.(int)$idAdmin.' AND email IS NOT NULL AND email != ""',
			'nome ASC'
		);

		while ($row = $results->fetchObject(Responsaveis::class)) {
			$motivo = EmailValidator::getRejeicao($row->email ?? '');
			if ($motivo === null) {
				continue;
			}
			$lista[] = [
				'tipo'    => 'Responsável',
				'id'      => (int)$row->id,
				'nome'    => $row->nome ?? '',
				'contato' => $row->email ?? '',
				'email'   => $row->email ?? '',
				'motivo'  => $motivo,
			];
			if (count($lista) >= $limite) {
				break;
			}
		}

		return $lista;
	}

	private static function auditarLeads(int $idAdmin, int $limite): array {
		$lista = [];
		$results = CrmLeads::getLeads(
			'id_admin = '.(int)$idAdmin.' AND email IS NOT NULL AND email != ""',
			'nome ASC'
		);

		while ($row = $results->fetchObject(CrmLeads::class)) {
			$motivo = EmailValidator::getRejeicao($row->email ?? '');
			if ($motivo === null) {
				continue;
			}
			$lista[] = [
				'tipo'    => 'Lead',
				'id'      => (int)$row->id,
				'nome'    => $row->nome ?? '',
				'contato' => $row->email ?? '',
				'email'   => $row->email ?? '',
				'motivo'  => $motivo,
			];
			if (count($lista) >= $limite) {
				break;
			}
		}

		return $lista;
	}

	private static function auditarAlunosWhatsapp(int $idAdmin, int $limite): array {
		$lista = [];
		$results = EntityUser::getUser(
			'nivel = "Cliente" AND id_admin = '.(int)$idAdmin.' AND whatsapp IS NOT NULL AND whatsapp != ""',
			'nome ASC'
		);

		while ($row = $results->fetchObject(EntityUser::class)) {
			$raw = trim((string)($row->whatsapp ?? ''));
			$motivo = self::getRejeicaoWhatsapp($raw, false);
			if ($motivo === null) {
				continue;
			}
			$lista[] = [
				'tipo'    => 'Aluno',
				'id'      => (int)$row->id,
				'nome'    => $row->nome ?? '',
				'contato' => $raw,
				'motivo'  => $motivo,
			];
			if (count($lista) >= $limite) {
				break;
			}
		}

		return $lista;
	}

	private static function auditarResponsaveisWhatsapp(int $idAdmin, int $limite): array {
		$lista = [];
		$results = Responsaveis::getRes(
			'id_admin = '.(int)$idAdmin.' AND whatsapp IS NOT NULL AND whatsapp != ""',
			'nome ASC'
		);

		while ($row = $results->fetchObject(Responsaveis::class)) {
			$raw = trim((string)($row->whatsapp ?? ''));
			$motivo = self::getRejeicaoWhatsapp($raw, false);
			if ($motivo === null) {
				continue;
			}
			$lista[] = [
				'tipo'    => 'Responsável',
				'id'      => (int)$row->id,
				'nome'    => $row->nome ?? '',
				'contato' => $raw,
				'motivo'  => $motivo,
			];
			if (count($lista) >= $limite) {
				break;
			}
		}

		return $lista;
	}

	private static function auditarLeadsWhatsapp(int $idAdmin, int $limite): array {
		$lista = [];
		$results = CrmLeads::getLeads(
			'id_admin = '.(int)$idAdmin.' AND whatsapp IS NOT NULL AND whatsapp != ""',
			'nome ASC'
		);

		while ($row = $results->fetchObject(CrmLeads::class)) {
			$raw = trim((string)($row->whatsapp ?? ''));
			$motivo = self::getRejeicaoWhatsapp($raw, false);
			if ($motivo === null) {
				continue;
			}
			$lista[] = [
				'tipo'    => 'Lead',
				'id'      => (int)$row->id,
				'nome'    => $row->nome ?? '',
				'contato' => $raw,
				'motivo'  => $motivo,
			];
			if (count($lista) >= $limite) {
				break;
			}
		}

		return $lista;
	}
}
