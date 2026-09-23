<?php

namespace App\Common\Helpers;

use App\Model\Entity\Certificados;
use App\Model\Entity\CjCandidato;
use App\Model\Entity\CjCandidatoFormacao;
use App\Model\Entity\CjNotificacao;
use App\Model\Entity\LmsCertificado;
use App\Model\Entity\Trilhas;
use App\Model\Entity\User;

/**
 * Sincroniza formação → cj_candidato_formacao.
 * Selo "Aluno certificado": apenas certificados emitidos pela escola (tabela certificados).
 * Certificados EAD (lms_certificados) entram como referência, sem selo.
 */
class ConectCandidatoFormacaoHelper {

	/** Certificado comercial emitido no painel da escola (/painel/certificados). */
	public static function syncFromCertificadoEscola(Certificados $cert): void {
		if (!CjCandidatoFormacao::tabelaExiste() || !CjCandidato::tabelaExiste()) {
			return;
		}

		$idAluno = (int)$cert->id_aluno;
		$idTrilha = (int)$cert->id_trilha;
		if ($idAluno <= 0 || $idTrilha <= 0) {
			return;
		}

		$user = User::getUserById($idAluno);
		if (!$user instanceof User || !ConectCandidatoAuthHelper::podeAcessarConect($user)) {
			return;
		}

		$candidato = ConectCandidatoAuthHelper::resolverPerfil($user);
		if (!$candidato instanceof CjCandidato) {
			return;
		}

		$trilha = Trilhas::getTrilhaById($idTrilha);
		$titulo = $trilha instanceof Trilhas ? trim((string)($trilha->nome ?? '')) : '';
		if ($titulo === '') {
			$titulo = trim((string)($cert->modulos ?? '')) ?: 'Formação certificada';
		}

		$conclusao = trim((string)($cert->conclusao ?? ''));
		if ($conclusao !== '') {
			$conclusao = mb_substr($conclusao, 0, 120);
		} else {
			$conclusao = null;
		}

		$dados = [
			'titulo'           => $titulo,
			'id_trilha'        => $idTrilha,
			'carga_h'          => (int)($cert->carga_h ?? 0) ?: null,
			'status'           => 'concluido',
			'selo_certificado' => 1,
			'concluido_em'     => $conclusao,
		];

		self::upsertTrilha((int)$candidato->id, $idAluno, $idTrilha, 'manual', $dados, true);
	}

	public static function removerCertificadoEscola(int $idAluno, int $idTrilha): void {
		if (!CjCandidatoFormacao::tabelaExiste() || $idAluno <= 0 || $idTrilha <= 0) {
			return;
		}
		$candidato = CjCandidato::getByUsuarioId($idAluno);
		if (!$candidato instanceof CjCandidato) {
			return;
		}
		CjCandidatoFormacao::excluirPorCandidatoTrilhaOrigem((int)$candidato->id, $idTrilha, 'manual');
	}

	/** Certificado simbólico EAD — referência no perfil, sem selo. */
	public static function syncFromLmsCertificado(LmsCertificado $cert): void {
		if (!CjCandidatoFormacao::tabelaExiste() || !CjCandidato::tabelaExiste()) {
			return;
		}

		$idAluno = (int)$cert->id_aluno;
		$idAdmin = (int)$cert->id_admin;
		$idCurso = (int)$cert->id_curso;
		if ($idAluno <= 0 || $idCurso <= 0) {
			return;
		}

		$user = User::getUserById($idAluno);
		if (!$user instanceof User || !ConectCandidatoAuthHelper::podeAcessarConect($user)) {
			return;
		}

		$candidato = ConectCandidatoAuthHelper::resolverPerfil($user);
		if (!$candidato instanceof CjCandidato) {
			return;
		}

		$valido = LmsCertificadoHelper::aindaValido($cert, $idAluno, $idAdmin);
		$conclusao = trim((string)($cert->conclusao ?? ''));
		if ($conclusao === '') {
			$conclusao = date('Y-m-d');
		}

		$dados = [
			'titulo'           => trim((string)($cert->titulo_curso ?? 'Curso online')),
			'id_trilha'        => (int)($cert->id_trilha ?? 0) ?: null,
			'carga_h'          => (int)($cert->carga_h ?? 0) ?: null,
			'status'           => $valido ? 'concluido' : 'em_andamento',
			'selo_certificado' => 0,
			'concluido_em'     => $valido ? $conclusao : null,
		];

		$existente = CjCandidatoFormacao::getByCandidatoCursoOrigem((int)$candidato->id, $idCurso, 'lms_auto');
		if ($existente) {
			CjCandidatoFormacao::atualizar((int)$existente['id'], $dados);
		} else {
			CjCandidatoFormacao::inserir(array_merge($dados, [
				'id_candidato' => (int)$candidato->id,
				'origem'       => 'lms_auto',
				'id_curso_lms' => $idCurso,
			]));
		}
	}

	/** @param array<string,mixed> $dados */
	private static function upsertTrilha(
		int $idCandidato,
		int $idUsuario,
		int $idTrilha,
		string $origem,
		array $dados,
		bool $notificarSelo
	): void {
		$existente = CjCandidatoFormacao::getByCandidatoTrilhaOrigem($idCandidato, $idTrilha, $origem);
		$eraSelo = $existente ? (int)($existente['selo_certificado'] ?? 0) : 0;

		if ($existente) {
			CjCandidatoFormacao::atualizar((int)$existente['id'], $dados);
		} else {
			CjCandidatoFormacao::inserir(array_merge($dados, [
				'id_candidato' => $idCandidato,
				'origem'       => $origem,
				'id_trilha'    => $idTrilha,
			]));
		}

		if ($notificarSelo && (int)($dados['selo_certificado'] ?? 0) === 1 && $eraSelo === 0 && CjNotificacao::tabelaExiste()) {
			CjNotificacao::inserir([
				'id_usuario' => $idUsuario,
				'tipo'       => 'selo',
				'titulo'     => 'Selo Aluno certificado',
				'mensagem'   => 'Seu certificado "'.$dados['titulo'].'" foi emitido pela escola parceira.',
				'link'       => '/candidato',
			]);
		}
	}

	public static function syncAllForUsuario(int $idUsuario): void {
		if ($idUsuario <= 0) {
			return;
		}
		$user = User::getUserById($idUsuario);
		if (!$user instanceof User) {
			return;
		}

		$idAdmin = (int)$user->id_admin;

		if (Certificados::tabelaExiste()) {
			$stmt = Certificados::listByAluno($idUsuario, $idAdmin);
			if ($stmt) {
				while ($cert = $stmt->fetchObject(Certificados::class)) {
					if ($cert instanceof Certificados) {
						self::syncFromCertificadoEscola($cert);
					}
				}
			}
		}

		if (LmsCertificado::tabelasExistem()) {
			LmsCertificadoHelper::backfillCursosCompletos($idAdmin, $idUsuario);
			$stmt = LmsCertificado::listByAluno($idUsuario, $idAdmin);
			if ($stmt) {
				while ($cert = $stmt->fetchObject(LmsCertificado::class)) {
					if ($cert instanceof LmsCertificado) {
						self::syncFromLmsCertificado($cert);
					}
				}
			}
		}
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarParaApi(int $idCandidato): array {
		$rows = CjCandidatoFormacao::listarPorCandidato($idCandidato);
		return array_map([ConectApiMapper::class, 'formacao'], $rows);
	}

	/** @deprecated use syncFromLmsCertificado */
	public static function syncFromCertificado(LmsCertificado $cert): void {
		self::syncFromLmsCertificado($cert);
	}
}
